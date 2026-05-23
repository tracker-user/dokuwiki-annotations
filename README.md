# Annotations Plugin for DokuWiki

Word- and sentence-level annotations on wiki pages, stored out-of-band with threaded replies. Inspired by [Hypothes.is](https://hypothes.is/) and [ep_comments_page](https://github.com/ether/ep_comments_page).

## Features

- **Text-quote anchoring** — select any word or sentence; the annotation is tied to the exact quoted text plus surrounding context, so it survives minor edits.
- **Threaded replies** — any reader can reply to an existing annotation.
- **Open / Resolved status** — mark discussions closed; resolved annotations turn green.
- **Gutter markers** — small icons in the left margin show at a glance where annotations live.
- **Orphan detection** — when the annotated text is removed from the page, the annotation is flagged as orphaned and accessible via the counter. Admins can bulk-delete orphans.
- **Per-user toggle** — users can turn the annotation overlay on or off via the usersettings plugin.
- **No page revisions** — annotations are stored in a separate JSON file per page; the wiki changelog is never touched.

## Requirements

- DokuWiki Librarian 2025-05-14b (or compatible release)
- PHP 8.0 or later with `mbstring` extension
- [usersettings plugin](https://github.com/tracker-user/dokuwiki-usersettings) *(optional — adds per-user on/off toggle)*

## Installation

1. Copy the `annotations/` directory into `{DokuWiki}/lib/plugins/`.
2. If you want the per-user toggle, install the usersettings plugin too.
3. No additional configuration is required.

## Usage

### Reading annotations

Annotated text is highlighted in **amber** (open) or **green** (resolved). Click any highlight to open the thread panel inline below that paragraph.

The counter bar above the page content shows the total number of annotations. If there are orphaned annotations (text was deleted), a clickable "N orphaned" link opens a drawer at the bottom of the page.

### Creating an annotation

1. Select any text on the page.
2. Click the **Annotate** button that appears.
3. Type your comment and click **Annotate** to save.

> Requires: logged in + at least read access on the page.

### Replying

Open the thread panel for any annotation and use the **Reply** field at the bottom.

### Resolving / Reopening

Click **Resolve** on any annotation to mark the discussion closed. Any reader can resolve or reopen.

### Editing and deleting

- Authors can edit or delete their own annotations and replies.
- Admins can edit or delete any annotation or reply.

### Admin: bulk operations

Admins see two extra buttons in the counter bar:

| Button | Effect |
|--------|--------|
| **Clear resolved** | Permanently deletes all resolved annotations on the page |
| **Clear orphaned** | Server-side re-check, then permanently deletes orphaned annotations |

### Disabling the overlay

Via **User Preferences** (usersettings plugin) → uncheck **Enable annotations**. The overlay is hidden for that user; annotations are still stored.

## Permission model

| Action | Who |
|--------|-----|
| Create annotation / reply | Logged in + AUTH_READ on page |
| Resolve / Reopen | Logged in + AUTH_READ on page |
| Edit / delete own annotation or reply | The author |
| Edit / delete any annotation or reply | Admins |
| Clear resolved / Clear orphaned (per-page) | Admins |

Note: **edit access is not required** to create an annotation. Groups whose page edit access is blocked can still annotate.

## Storage

Each page's annotations are stored at:

```
{data}/meta/{namespace}/{page}.annotations
```

Format (JSON):

```json
{
  "version": 1,
  "annotations": [
    {
      "id": "a1b2c3d4e5f6g7h8",
      "anchor": {
        "exact": "lossless source",
        "prefix": "must use a ",
        "suffix": ". Transcodes",
        "start": 21
      },
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

## AJAX API

All actions go to `/lib/exe/ajax.php?call=annotations`.

| Action | Method | Token | Required fields |
|--------|--------|-------|-----------------|
| `load` | GET | — | `id` |
| `create` | POST | ✓ | `id`, `anchor`, `body` |
| `reply` | POST | ✓ | `id`, `annId`, `body` |
| `edit_annotation` | POST | ✓ | `id`, `annId`, `body` |
| `edit_reply` | POST | ✓ | `id`, `annId`, `replyId`, `body` |
| `delete_annotation` | POST | ✓ | `id`, `annId` |
| `delete_reply` | POST | ✓ | `id`, `annId`, `replyId` |
| `resolve` | POST | ✓ | `id`, `annId`, `status` |
| `clear_resolved` | POST | ✓ | `id` |
| `clear_orphaned` | POST | ✓ | `id` |

POST bodies are `application/json`. All responses: `{"success": true, ...}` or `{"success": false, "error": "..."}`.

## Files

```
annotations/
├── plugin.info.txt     Plugin manifest
├── helper.php          Storage, CRUD, orphan detection, permission rules
├── action.php          Event hooks, AJAX endpoint
├── script.js           Front-end: selection, anchoring, highlights, panels, AJAX
├── style.css           Theme-compatible CSS (uses DokuWiki __token__ vars)
├── README.md           This file
└── lang/
    └── en/
        └── lang.php    English strings
```

## Browser compatibility

The JavaScript targets Firefox 78 ESR and later (no `#private` fields, no `??=`, no `<dialog>`, no `Array.at`). Should work in all modern browsers.

## License

GPL 2, matching DokuWiki.
