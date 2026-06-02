# Annotations Plugin — Design & Architecture

A developer reference for the annotations plugin. For installation and end-user
behaviour see [README.md](README.md); for the wider review/environment
conventions see `CLAUDE.md` in the plugins root.

## Concept

Word- and sentence-level annotations on wiki pages, in the spirit of
Hypothes.is and `ep_comments_page`:

- **Out-of-band.** Annotations live in a separate per-page JSON file, never in
  the page text or the wiki changelog. Creating one needs only `AUTH_READ`, so
  a group whose page *edit* access is blocked can still annotate.
- **Text-quote anchored.** Each annotation is tied to the quoted text plus a
  little surrounding context, not to a character position, so it survives minor
  edits and is re-found in the rendered DOM on each page load.
- **Threaded.** Annotations carry replies; both have open/resolved status at the
  annotation level.
- **Orphan-aware.** When the quoted text disappears from the page the annotation
  becomes an *orphan* — still stored, surfaced through a counter, and bulk-
  removable by an admin.

## Components

| File | Owns |
|------|------|
| `plugin.info.txt` | Manifest: name, author, version date, description, repository URL. |
| `helper.php` | The per-page store, all CRUD, server-side orphan detection, and the **permission rules as the single source of truth**. Pure logic — permission methods take facts (user, admin flag, ACL level) as parameters and read no globals. |
| `action.php` | Event registration; injecting the page payload into `JSINFO`; the AJAX endpoint and **permission enforcement** (gathers facts from DokuWiki globals, calls the helper). |
| `script.js` | All front-end behaviour: boot/gate, load + re-anchor, highlights, gutter markers, counter, selection→new-annotation flow, thread panels, and AJAX. Plain IIFE, vanilla JS. |
| `style.css` | Styling via DokuWiki theme tokens (`__background__`, `__text__`, …). Only the amber (open) / green (resolved) highlight colours are hard-coded. |
| `lang/en/lang.php` | The usersettings toggle label/description (used) plus a set of UI strings that are **not yet wired into the JS** — see *Known gaps*. |

Documentation lives in [`README.md`](README.md) (end users) and this file
(developers); the licence is in `LICENSE` (GPL 2).

## Data model & storage

One pretty-printed JSON file per page at `metaFN($id, '.annotations')`
(`data/meta/<namespace>/<page>.annotations`):

```json
{
  "version": 1,
  "annotations": [
    {
      "id": "a1b2c3d4e5f6g7h8",
      "anchor": { "exact": "...", "prefix": "...", "suffix": "...", "start": 123 },
      "author": "alice",
      "created": 1716336000,
      "modified": 1716336000,
      "body": "Does this cover remuxes?",
      "status": "open",
      "resolved_by": "",
      "resolved_at": 0,
      "replies": [
        {
          "id": "x1y2z3a4b5c6d7e8",
          "author": "bob",
          "created": 1716336100,
          "modified": 1716336100,
          "body": "Yes, remuxes count."
        }
      ]
    }
  ]
}
```

Limits and identifiers (`helper.php` constants): `SCHEMA_VERSION = 1`,
`MAX_QUOTE = 1000`, `MAX_CONTEXT = 64`, `MAX_BODY = 10000`. IDs are
`bin2hex(random_bytes(8))` — 16 hex chars. Writes go through `io_lock()` →
modify → `io_saveFile()` → `io_unlock()` (the `mutate()` helper); a modifier
returning `false` aborts the write (used for "target not found").

## Text-quote anchoring

An anchor is `{exact, prefix, suffix, start}`:

- `exact` — the selected text, whitespace-normalised (runs collapsed to one
  space, trimmed). The same normalisation is applied on capture (JS), on
  storage (PHP), and on matching, so client and server agree.
- `prefix` / `suffix` — context on each side to disambiguate a quote that
  appears more than once. Client captures ~30 chars; server caps at 64.
- `start` — a character-offset hint into the page text, used only as a
  tie-breaker.

**Re-anchoring (client, `findRange`)**: collect the content text with a
`TreeWalker`, search for the normalised `exact`, disambiguate repeats with
`prefix`/`suffix`, tie-break with the `start` hint, then map the chosen
character offset back to a DOM `Range` and wrap it in a highlight `<span>`. A
quote that cannot be located is an orphan (no highlight, no gutter marker).

## Orphan detection (two layers)

- **Client (live UI).** Anything `findRange` cannot anchor on page load is
  counted as orphaned; the count feeds the counter bar, and the orphaned link
  opens a drawer at the bottom of the content area with those threads.
- **Server (authoritative, `findOrphaned`).** For the admin "clear orphaned"
  action the page is rendered with `p_wiki_xhtml`, block-closing tags are turned
  into spaces, tags/entities are stripped, whitespace normalised, and each
  annotation's `exact` is searched with `mb_strpos`. This re-check is the source
  of truth for deletion, so a stale client can't cause data loss.

## JSINFO injection (important gotcha)

`script.js` needs per-page facts at boot without an extra round-trip, but you
**cannot** add them by writing `$JSINFO` inside `TPL_METAHEADER_OUTPUT`:
`tpl_metaheaders()` calls `jsinfo()` and serialises `$JSINFO` into the inline
`var JSINFO = …;` script **before** firing that event. Instead `handleMetaHeader`
finds that inline `<script>` in `$event->data['script']` and appends a
`JSINFO.annotations = {…};` statement so it runs in the same scope. Injection is
gated to `show` / `export_xhtml` views.

Payload: `{ enabled, pageId, stats, user, isAdmin, token }`. `user`, `isAdmin`
and `token` are included because stock `JSINFO` exposes no user identity and no
security token — the script reads them from `JSINFO.annotations`, not from
`JSINFO.userinfo` (which does not exist) or the `#dw__token` field.

## Per-user toggle

Registered with the **usersettings** plugin via `PLUGIN_USERSETTINGS_REGISTER`
(key `annotations_enabled`, checkbox, default on). `isEnabledForUser()` reads the
preference through the usersettings helper; if that plugin is absent, or the
toggle has not been registered yet, the feature defaults to **on**. When a user
turns it off, `boot()` returns early and nothing is rendered (annotations are
still stored).

## Permission model

The rules live in `helper.php` and are pure; `action.php` gathers the facts and
calls them. `isAdmin` is true for the `admin` group or DokuWiki's `$INFO['isadmin']`.

| Action | Rule (helper method) |
|--------|----------------------|
| Create annotation / reply / resolve / reopen | logged in **and** `AUTH_READ` on the page — *not* `AUTH_EDIT` (`canAnnotate`) |
| Edit / delete own annotation | author (`canEditAnnotation`) |
| Edit / delete own reply | author (`canEditReply`) |
| Edit / delete **any** annotation or reply | admin (`canEditAnnotation` / `canEditReply`) |
| Clear resolved / clear orphaned (per page) | admin (`canClear`) |
| Load (read) annotations | `AUTH_READ` on the page |

## Security

- **CSRF.** Every state-changing action requires a valid DokuWiki security
  token. The token is injected into `JSINFO.annotations.token` and sent back as
  `sectok` in the JSON body. Because `checkSecurityToken()` reads `$_REQUEST`
  (empty for a JSON body), `handleAjax` copies `sectok` into `$_POST`/`$_REQUEST`
  before validating. The read-only `load` action is exempt (GET, no token) but
  still ACL-checked.
- **ACL.** `auth_quickaclcheck($id)` gates both reading and writing.
- **Output.** Bodies are stored as plain text (newlines kept, length-capped) and
  rendered client-side via `textContent`, so user content is never interpolated
  as HTML.

## AJAX endpoint

`…/lib/exe/ajax.php?call=annotations` (handled on `AJAX_CALL_UNKNOWN`). The
`load` action is a GET with query params; everything else is `POST` with an
`application/json` body. Every response is `{ "success": true, … }` or
`{ "success": false, "error": "…" }`.

| Action | Method | Token | Extra fields |
|--------|--------|-------|--------------|
| `load` | GET | — | — |
| `create` | POST | ✓ | `anchor`, `body` |
| `reply` | POST | ✓ | `annId`, `body` |
| `edit_annotation` | POST | ✓ | `annId`, `body` |
| `edit_reply` | POST | ✓ | `annId`, `replyId`, `body` |
| `delete_annotation` | POST | ✓ | `annId` |
| `delete_reply` | POST | ✓ | `annId`, `replyId` |
| `resolve` | POST | ✓ | `annId`, `status` (`open`\|`resolved`) |
| `clear_resolved` | POST | ✓ | — |
| `clear_orphaned` | POST | ✓ | — |

All actions also take the page `id`.

## Constraints

- **JS/CSS floor: Firefox 78 ESR.** No `#private` fields, `??=`/`||=`/`&&=`,
  `Array.at`, `structuredClone`, `Object.hasOwn`, native `<dialog>`; no CSS
  `:has()`, selector `:not()`, `aspect-ratio`, container queries, or nesting.
  `async`/`await`, `fetch`, classes, `?.`, `??`, `Map`/`Set` are fine.
- **PHP:** developed against 8.3; requires the `mbstring` extension.

## Known gaps / next steps

- **UI localisation.** `script.js` renders hardcoded English; `annInfo.lang` is
  never populated, so the `counter_*`, `btn_*`, `status_*`, `placeholder_*`,
  `tooltip_*`, `orphaned_*`, `error_*` and `confirm_*` strings in
  `lang/en/lang.php` are currently dead. To localise: inject `lang` into the
  `JSINFO.annotations` payload in `handleMetaHeader` and read `_lang` in the JS
  string sites. Only `toggle_label` / `toggle_desc` are wired (via `getLang`).
- **Translations.** No `de` / `ru` / `ja` yet (depends on the localisation work
  above).
- **Tests.** No `_test/` suite. Candidates: helper CRUD, input cleaning,
  permission rules, and `findOrphaned` against a rendered page.
- **Config.** No `conf/` — nothing is configurable (highlight colours, context
  length, body cap are all constants/CSS).
- **Cleanup.** The `ann-highlight-orphaned` JS constant has no CSS rule and no
  call site (orphans have no in-page range to highlight).
