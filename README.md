# Annotations Plugin for DokuWiki

Word- and sentence-level annotations on wiki pages, stored separately from the page text with threaded replies. Inspired by [Hypothes.is](https://hypothes.is/) and [ep_comments_page](https://github.com/ether/ep_comments_page).

## Features

- **Text-quote anchoring** — select any word or sentence; the annotation is tied to the quoted text plus its surrounding context, so it survives minor edits to the page.
- **Threaded replies** — any logged-in reader can reply to an existing annotation.
- **Open / Resolved status** — mark a discussion closed; resolved annotations turn green.
- **Gutter markers** — small icons in the left margin show at a glance where annotations live.
- **Orphan detection** — when the annotated text is removed from the page, the annotation is flagged as orphaned and stays reachable via the counter. Admins can bulk-delete orphans.
- **Per-user toggle** — turn the annotation overlay on or off from your user preferences.
- **No page revisions** — annotations live in a separate file per page; the wiki changelog is never touched.

## Requirements

- DokuWiki Librarian 2025-05-14b (or a compatible release)
- PHP 8.0 or later with the `mbstring` extension
- [usersettings plugin](https://github.com/tracker-user/dokuwiki-usersettings) *(optional — adds the per-user on/off toggle)*

Works in current browsers and as far back as Firefox 78 ESR.

## Installation

1. Copy the `annotations/` directory into `{DokuWiki}/lib/plugins/`.
2. If you want the per-user toggle, install the usersettings plugin too.
3. No additional configuration is required.

## Usage

### Reading annotations

Annotated text is highlighted in **amber** (open) or **green** (resolved). Click any highlight to open the thread panel inline below that paragraph.

The counter bar above the page content shows how many annotations the page has. If some are orphaned (their text was deleted), a clickable "N orphaned" link opens a drawer at the bottom of the page with those threads.

### Creating an annotation

1. Select any text on the page.
2. Click the **Annotate** button that appears.
3. Type your comment and click **Annotate** to save.

> You only need to be logged in and able to *read* the page — edit access is **not** required. If you can read a page, you can annotate it.

### Replying

Open the thread panel for any annotation and use the **Reply** field at the bottom. Any logged-in reader can reply.

### Resolving / reopening

Click **Resolve** on an annotation to mark its discussion closed, or **Reopen** to undo that. Any logged-in reader can resolve or reopen.

### Editing and deleting

- You can edit or delete your own annotations and replies.
- Admins can edit or delete anyone's.

### Admin: bulk operations

Admins see two extra buttons in the counter bar:

| Button | Effect |
|--------|--------|
| **Clear resolved** | Permanently deletes all resolved annotations on the page |
| **Clear orphaned** | Re-checks the page, then permanently deletes the orphaned annotations |

### Turning the overlay off

In **User Preferences** (provided by the usersettings plugin), uncheck **Enable annotations**. The overlay is then hidden for you; your annotations are still stored and remain visible to everyone else.

## License

GPL 2, matching DokuWiki.

---

**Developers & AI agents:** see **[DESIGN.md](DESIGN.md)** for the full technical reference — architecture, the text-quote anchoring algorithm, the JSON storage format, the JSINFO-injection mechanism, the permission model, the AJAX API, browser/PHP constraints, and known gaps.
