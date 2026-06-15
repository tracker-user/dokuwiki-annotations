<?php

/**
 * Annotations plugin — storage and data-logic helper.
 *
 * This component owns:
 *
 *   1. The per-page annotation store. One JSON file per page, obtained via
 *      metaFN($id, '.annotations'), holding {version, annotations:[...]}.
 *      JSON and pretty-printed so the files are easy to inspect or back up.
 *      The page text and the wiki changelog are never touched.
 *
 *   2. The text-quote anchor model. Each annotation stores an anchor of
 *      {exact, prefix, suffix, start} — the quoted text, a short slice of the
 *      surrounding context on each side (to disambiguate repeated quotes),
 *      and a character-offset hint. This is the Hypothes.is approach.
 *
 *   3. CRUD on annotations and their threaded replies.
 *
 *   4. Server-side orphan detection: a page is rendered to plain text and an
 *      annotation is "orphaned" when its quoted text no longer appears. Used
 *      by the admin-only per-page "clear orphaned" operation. (The live UI
 *      also detects orphans client-side for the on-page counter.)
 *
 *   5. The permission rules, as the single source of truth. They are pure
 *      functions: the caller gathers the facts (current user, admin flag, the
 *      page's ACL level) and passes them in. Because annotations live
 *      out-of-band, creating one needs only AUTH_READ on the page, never
 *      AUTH_EDIT — so a group whose page edit access is blocked can still
 *      annotate.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

class helper_plugin_annotations extends DokuWiki_Plugin
{
    /** storage schema version, written into each file */
    const SCHEMA_VERSION = 1;

    /** longest quoted selection stored, in characters */
    const MAX_QUOTE = 1000;

    /** length of the prefix/suffix context slices, in characters (config fallback) */
    const DEFAULT_CONTEXT = 64;

    /** longest annotation/reply body, in characters (config fallback) */
    const DEFAULT_BODY = 10000;

    /**
     * Configured length of each prefix/suffix context slice, in characters.
     *
     * @return int
     */
    protected function contextLength()
    {
        $v = (int) $this->getConf('context_length');
        return $v >= 0 ? $v : self::DEFAULT_CONTEXT;
    }

    /**
     * Configured maximum annotation/reply body length, in characters.
     *
     * @return int
     */
    protected function bodyCap()
    {
        $v = (int) $this->getConf('body_cap');
        return $v > 0 ? $v : self::DEFAULT_BODY;
    }

    // ---------------------------------------------------------------------
    //  Storage
    // ---------------------------------------------------------------------

    /**
     * Path of a page's annotation file.
     *
     * @param string $id page id
     * @return string
     */
    protected function getFile($id)
    {
        return metaFN($id, '.annotations');
    }

    /**
     * All annotations stored for a page.
     *
     * @param string $id page id
     * @return array list of annotation arrays (empty if none)
     */
    public function getAnnotations($id)
    {
        $file = $this->getFile($id);
        if (!file_exists($file)) {
            return [];
        }
        $raw = io_readFile($file, false);
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['annotations']) || !is_array($data['annotations'])) {
            return [];
        }
        return $data['annotations'];
    }

    /**
     * A single annotation by id.
     *
     * @param string $id    page id
     * @param string $annId annotation id
     * @return array|null
     */
    public function getAnnotation($id, $annId)
    {
        foreach ($this->getAnnotations($id) as $a) {
            if (($a['id'] ?? '') === $annId) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Counts for the on-page indicator. The orphan count is deliberately not
     * here — it depends on the rendered page and is computed client-side.
     *
     * @param string $id page id
     * @return array ['total'=>int, 'open'=>int, 'resolved'=>int]
     */
    public function getStats($id)
    {
        return $this->statsFor($this->getAnnotations($id));
    }

    /**
     * Counts for the on-page indicator, computed from an already-loaded list.
     * Split out from getStats() so callers that already hold the annotation
     * array (e.g. the page-load JSINFO injector, which embeds the same list)
     * don't re-read the file.
     *
     * @param array $annotations annotation list
     * @return array ['total'=>int, 'open'=>int, 'resolved'=>int]
     */
    public function statsFor(array $annotations)
    {
        $open = 0;
        $resolved = 0;
        foreach ($annotations as $a) {
            if (($a['status'] ?? 'open') === 'resolved') {
                $resolved++;
            } else {
                $open++;
            }
        }
        return ['total' => $open + $resolved, 'open' => $open, 'resolved' => $resolved];
    }

    /**
     * Write a page's annotation list to disk.
     *
     * @param string $id   page id
     * @param array  $list annotations
     * @return bool
     */
    protected function writeFile($id, array $list)
    {
        $payload = [
            'version'     => self::SCHEMA_VERSION,
            'annotations' => array_values($list),
        ];
        return (bool) io_saveFile(
            $this->getFile($id),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Run a modification against a page's annotations under a write lock.
     *
     * The modifier receives the annotation list by reference and returns an
     * outcome value. Returning the boolean false aborts the write (used for
     * "target not found"); any other value is returned to the caller after a
     * successful save.
     *
     * @param string   $id       page id
     * @param callable $modifier function(array &$annotations): mixed
     * @return mixed  the modifier's outcome on success, or false on failure
     */
    protected function mutate($id, callable $modifier)
    {
        $file = $this->getFile($id);
        // Lock on a sentinel key, NOT $file itself: writeFile() below calls
        // io_saveFile($file), which takes its own io_lock($file) internally.
        // Locking $file here would collide with that inner lock — io_lock
        // busy-waits ~3s for the stale-lock timeout on every write and then
        // proceeds, defeating mutual exclusion (see DokuWiki TaskRunner). A
        // distinct key serialises the read-modify-write across requests while
        // leaving io_saveFile's lock uncontended.
        $lock = $file . '.lock';
        io_lock($lock);

        $annotations = $this->getAnnotations($id);
        $outcome = $modifier($annotations);

        if ($outcome === false) {
            io_unlock($lock);
            return false;
        }

        $ok = $this->writeFile($id, $annotations);
        io_unlock($lock);
        return $ok ? $outcome : false;
    }

    // ---------------------------------------------------------------------
    //  Annotation CRUD
    // ---------------------------------------------------------------------

    /**
     * Create an annotation.
     *
     * @param string $id     page id
     * @param array  $anchor raw anchor {exact, prefix, suffix, start}
     * @param string $author username
     * @param string $body   annotation text
     * @return array|false  the created annotation, or false on invalid input
     */
    public function createAnnotation($id, $anchor, $author, $body)
    {
        if ($id === '' || $author === '' || $author === null) {
            return false;
        }
        $body = $this->cleanBody($body);
        if ($body === '') {
            return false;
        }
        $anchor = $this->cleanAnchor($anchor);
        if ($anchor === null) {
            return false;
        }

        $now = time();
        $new = [
            'id'          => $this->newId(),
            'anchor'      => $anchor,
            'author'      => $author,
            'created'     => $now,
            'modified'    => $now,
            'body'        => $body,
            'status'      => 'open',
            'resolved_by' => '',
            'resolved_at' => 0,
            'replies'     => [],
        ];

        return $this->mutate($id, function (array &$annotations) use ($new) {
            $annotations[] = $new;
            return $new;
        });
    }

    /**
     * Edit an annotation's body text.
     *
     * @param string $id    page id
     * @param string $annId annotation id
     * @param string $body  new text
     * @return bool
     */
    public function updateAnnotationBody($id, $annId, $body)
    {
        $body = $this->cleanBody($body);
        if ($body === '') {
            return false;
        }
        return (bool) $this->mutate($id, function (array &$annotations) use ($annId, $body) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') === $annId) {
                    $annotations[$i]['body']     = $body;
                    $annotations[$i]['modified'] = time();
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Delete an annotation and all its replies.
     *
     * @param string $id    page id
     * @param string $annId annotation id
     * @return bool
     */
    public function deleteAnnotation($id, $annId)
    {
        return (bool) $this->mutate($id, function (array &$annotations) use ($annId) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') === $annId) {
                    array_splice($annotations, $i, 1);
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Mark an annotation open or resolved.
     *
     * @param string $id     page id
     * @param string $annId  annotation id
     * @param string $status 'open' or 'resolved'
     * @param string $actor  username making the change (recorded when resolving)
     * @return bool
     */
    public function setStatus($id, $annId, $status, $actor)
    {
        if (!in_array($status, ['open', 'resolved'], true)) {
            return false;
        }
        return (bool) $this->mutate($id, function (array &$annotations) use ($annId, $status, $actor) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') === $annId) {
                    $annotations[$i]['status'] = $status;
                    if ($status === 'resolved') {
                        $annotations[$i]['resolved_by'] = $actor;
                        $annotations[$i]['resolved_at'] = time();
                    } else {
                        $annotations[$i]['resolved_by'] = '';
                        $annotations[$i]['resolved_at'] = 0;
                    }
                    return true;
                }
            }
            return false;
        });
    }

    // ---------------------------------------------------------------------
    //  Reply CRUD
    // ---------------------------------------------------------------------

    /**
     * Add a reply to an annotation.
     *
     * @param string $id       page id
     * @param string $annId    annotation id
     * @param string $author   username
     * @param string $body     reply text
     * @param string $parentId id of the reply being replied to, or '' for root-level
     * @return array|false  the created reply, or false on invalid input
     */
    public function addReply($id, $annId, $author, $body, $parentId = '')
    {
        if ($author === '' || $author === null) {
            return false;
        }
        $body = $this->cleanBody($body);
        if ($body === '') {
            return false;
        }
        $now = time();
        $reply = [
            'id'       => $this->newId(),
            'parentId' => preg_replace('/[^a-f0-9]/', '', (string) $parentId),
            'author'   => $author,
            'created'  => $now,
            'modified' => $now,
            'body'     => $body,
        ];

        return $this->mutate($id, function (array &$annotations) use ($annId, $reply) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') === $annId) {
                    $annotations[$i]['replies'][] = $reply;
                    return $reply;
                }
            }
            return false;
        });
    }

    /**
     * Edit a reply's body text.
     *
     * @param string $id      page id
     * @param string $annId   annotation id
     * @param string $replyId reply id
     * @param string $body    new text
     * @return bool
     */
    public function updateReply($id, $annId, $replyId, $body)
    {
        $body = $this->cleanBody($body);
        if ($body === '') {
            return false;
        }
        return (bool) $this->mutate($id, function (array &$annotations) use ($annId, $replyId, $body) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') !== $annId) {
                    continue;
                }
                foreach (($a['replies'] ?? []) as $j => $r) {
                    if (($r['id'] ?? '') === $replyId) {
                        $annotations[$i]['replies'][$j]['body']     = $body;
                        $annotations[$i]['replies'][$j]['modified'] = time();
                        return true;
                    }
                }
            }
            return false;
        });
    }

    /**
     * Delete a reply.
     *
     * @param string $id      page id
     * @param string $annId   annotation id
     * @param string $replyId reply id
     * @return bool
     */
    public function deleteReply($id, $annId, $replyId)
    {
        return (bool) $this->mutate($id, function (array &$annotations) use ($annId, $replyId) {
            foreach ($annotations as $i => $a) {
                if (($a['id'] ?? '') !== $annId) {
                    continue;
                }
                foreach (($a['replies'] ?? []) as $j => $r) {
                    if (($r['id'] ?? '') === $replyId) {
                        array_splice($annotations[$i]['replies'], $j, 1);
                        return true;
                    }
                }
            }
            return false;
        });
    }

    // ---------------------------------------------------------------------
    //  Bulk maintenance (admin, per page)
    // ---------------------------------------------------------------------

    /**
     * Remove every resolved annotation from a page.
     *
     * @param string $id page id
     * @return int|false number removed, or false on write failure
     */
    public function clearResolved($id)
    {
        if (empty($this->getAnnotations($id))) {
            return 0;
        }
        return $this->mutate($id, function (array &$annotations) {
            $before = count($annotations);
            $annotations = array_values(array_filter($annotations, function ($a) {
                return ($a['status'] ?? 'open') !== 'resolved';
            }));
            return $before - count($annotations);
        });
    }

    /**
     * Remove every resolved annotation from every annotated page.
     *
     * The wiki-wide companion to clearResolved(), mirroring clearOrphanedAll():
     * iterates the pages found by getAnnotatedPages() and applies the per-page
     * clearResolved() to each. A page whose write fails is skipped rather than
     * aborting the whole sweep.
     *
     * @return int total number of annotations removed across all pages
     */
    public function clearResolvedAll()
    {
        $removed = 0;
        foreach ($this->getAnnotatedPages() as $id) {
            $n = $this->clearResolved($id);
            if ($n !== false) {
                $removed += (int) $n;
            }
        }
        return $removed;
    }

    /**
     * Remove every orphaned annotation from a page — those whose quoted text
     * no longer appears in the rendered page. The page is re-checked here, so
     * this is authoritative regardless of what a client believed.
     *
     * @param string $id page id
     * @return int|false number removed, or false on write failure
     */
    public function clearOrphaned($id)
    {
        $orphanIds = [];
        foreach ($this->findOrphaned($id) as $a) {
            $orphanIds[] = $a['id'];
        }
        if (empty($orphanIds)) {
            return 0;
        }
        return $this->mutate($id, function (array &$annotations) use ($orphanIds) {
            $before = count($annotations);
            $annotations = array_values(array_filter($annotations, function ($a) use ($orphanIds) {
                return !in_array($a['id'] ?? '', $orphanIds, true);
            }));
            return $before - count($annotations);
        });
    }

    /**
     * Remove every orphaned annotation from every annotated page.
     *
     * Iterates the pages found by getAnnotatedPages() and applies the per-page
     * clearOrphaned() to each, so the same authoritative re-check runs for
     * every page. A page whose write fails is skipped rather than aborting the
     * whole sweep.
     *
     * @return int total number of annotations removed across all pages
     */
    public function clearOrphanedAll()
    {
        $removed = 0;
        foreach ($this->getAnnotatedPages() as $id) {
            $n = $this->clearOrphaned($id);
            if ($n !== false) {
                $removed += (int) $n;
            }
        }
        return $removed;
    }

    // ---------------------------------------------------------------------
    //  Orphan detection
    // ---------------------------------------------------------------------

    /**
     * Render a page to normalised plain text, for quote searching.
     *
     * Block-level closing tags become spaces so adjacent blocks do not fuse
     * into one run of text; then tags are stripped, entities decoded, and
     * whitespace collapsed — the same normalisation applied to stored quotes.
     *
     * @param string $id page id
     * @return string
     */
    public function getPageText($id)
    {
        if (!page_exists($id)) {
            return '';
        }
        $xhtml = p_wiki_xhtml($id, '', false);
        if (!is_string($xhtml) || $xhtml === '') {
            return '';
        }
        $xhtml = preg_replace('#</(p|div|li|h[1-6]|td|th|tr|blockquote|pre|dt|dd)>#i', ' ', $xhtml);
        $xhtml = preg_replace('#<br\s*/?>#i', ' ', $xhtml);
        $text  = strip_tags($xhtml);
        $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $this->normalizeWhitespace($text);
    }

    /**
     * The annotations on a page whose quoted text is no longer present.
     *
     * @param string $id page id
     * @return array list of orphaned annotation arrays
     */
    public function findOrphaned($id)
    {
        $annotations = $this->getAnnotations($id);
        if (empty($annotations)) {
            return [];
        }
        $pageText = $this->getPageText($id);

        $orphaned = [];
        foreach ($annotations as $a) {
            if ($this->quoteMissing($a, $pageText)) {
                $orphaned[] = $a;
            }
        }
        return $orphaned;
    }

    /**
     * Whether an annotation's quoted text is absent from the page text.
     *
     * The single orphan rule, shared by findOrphaned() and pageCounts(): an
     * annotation is orphaned when its (normalised) quoted text no longer
     * appears in the (normalised) rendered page text.
     *
     * @param array  $annotation annotation array
     * @param string $pageText   normalised plain-text page body (see getPageText)
     * @return bool
     */
    protected function quoteMissing(array $annotation, $pageText)
    {
        $exact = $this->normalizeWhitespace($annotation['anchor']['exact'] ?? '');
        return $exact === '' || mb_strpos($pageText, $exact) === false;
    }

    // ---------------------------------------------------------------------
    //  Admin overview (enumeration & counts)
    // ---------------------------------------------------------------------

    /**
     * Every page that currently has at least one stored annotation.
     *
     * Scans the meta directory for ".annotations" files and maps each back to a
     * page id. Files left behind with an empty annotation list (every
     * annotation since deleted) are skipped, so the result matches what the
     * admin overview shows.
     *
     * @return string[] page ids, in the natural order search() yields
     */
    public function getAnnotatedPages()
    {
        global $conf;
        $dir = $conf['metadir'];
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        search($found, $dir, [$this, 'searchAnnotations'], []);

        $ids = [];
        foreach ($found as $item) {
            if (!empty($this->getAnnotations($item['id']))) {
                $ids[] = $item['id'];
            }
        }
        return $ids;
    }

    /**
     * search() callback collecting ".annotations" files as page ids.
     *
     * Directories are always traversed; files are matched on the extension and
     * the derived id is validated with cleanID() so anything that is not a real
     * page id is skipped.
     *
     * @param array  $data &$ result accumulator (each entry ['id' => string])
     * @param string $base search root (the meta directory)
     * @param string $file current path relative to $base (leading slash)
     * @param string $type 'd' for directory, 'f' for file
     * @param int    $lvl  recursion depth
     * @param array  $opts options passed to search() (unused)
     * @return bool whether search() should recurse into a directory
     */
    public function searchAnnotations(&$data, $base, $file, $type, $lvl, $opts)
    {
        if ($type === 'd') {
            return true; // recurse into namespaces
        }
        if (!str_ends_with($file, '.annotations')) {
            return false;
        }
        $id = pathID(substr($file, 0, -strlen('.annotations')));
        if ($id === '' || $id !== cleanID($id)) {
            return false;
        }
        $data[] = ['id' => $id];
        return false;
    }

    /**
     * Annotation counts for one page, for the admin overview.
     *
     * The page is rendered once (getPageText) and every annotation tested with
     * the shared quoteMissing() rule, so "normal" here means exactly "not
     * orphaned" — the same definition the per-page clear-orphaned uses.
     *
     * "resolved" counts every annotation whose status is resolved, regardless of
     * whether it is also orphaned, so it matches exactly what clearResolved()
     * removes. The facets therefore overlap: a resolved-and-present annotation is
     * counted in both "normal" and "resolved"; a resolved-and-orphaned one in
     * both "orphaned" and "resolved".
     *
     * @param string $id page id
     * @return array ['total'=>int, 'normal'=>int, 'resolved'=>int, 'orphaned'=>int]
     */
    public function pageCounts($id)
    {
        $annotations = $this->getAnnotations($id);
        $total = count($annotations);
        if ($total === 0) {
            return ['total' => 0, 'normal' => 0, 'resolved' => 0, 'orphaned' => 0];
        }
        $pageText = $this->getPageText($id);
        $orphaned = 0;
        $resolved = 0;
        foreach ($annotations as $a) {
            if ($this->quoteMissing($a, $pageText)) {
                $orphaned++;
            }
            if (($a['status'] ?? 'open') === 'resolved') {
                $resolved++;
            }
        }
        return [
            'total'    => $total,
            'normal'   => $total - $orphaned,
            'resolved' => $resolved,
            'orphaned' => $orphaned,
        ];
    }

    // ---------------------------------------------------------------------
    //  Permission rules (single source of truth)
    // ---------------------------------------------------------------------

    /**
     * May this user create an annotation, reply, or change a resolve status?
     *
     * Requires only read access to the page — annotations are out-of-band, so
     * a user whose page edit access is blocked may still annotate.
     *
     * @param string $user     current username ('' for anonymous)
     * @param int    $aclLevel the user's ACL level on the page
     * @return bool
     */
    public function canAnnotate($user, $aclLevel)
    {
        return $user !== '' && $user !== null && $aclLevel >= AUTH_READ;
    }

    /**
     * May this user edit or delete the given annotation? Author or admin.
     *
     * @param array  $annotation
     * @param string $user
     * @param bool   $isAdmin
     * @return bool
     */
    public function canEditAnnotation(array $annotation, $user, $isAdmin)
    {
        if ($user === '' || $user === null) {
            return false;
        }
        return $isAdmin || (($annotation['author'] ?? '') === $user);
    }

    /**
     * May this user edit or delete the given reply? Author or admin.
     *
     * @param array  $reply
     * @param string $user
     * @param bool   $isAdmin
     * @return bool
     */
    public function canEditReply(array $reply, $user, $isAdmin)
    {
        if ($user === '' || $user === null) {
            return false;
        }
        return $isAdmin || (($reply['author'] ?? '') === $user);
    }

    /**
     * May this user run the per-page "clear resolved/orphaned" operations?
     * Admins only.
     *
     * @param bool $isAdmin
     * @return bool
     */
    public function canClear($isAdmin)
    {
        return (bool) $isAdmin;
    }

    // ---------------------------------------------------------------------
    //  Input cleaning
    // ---------------------------------------------------------------------

    /**
     * Validate and normalise a raw anchor.
     *
     * @param mixed $anchor
     * @return array|null  the cleaned anchor, or null if unusable
     */
    protected function cleanAnchor($anchor)
    {
        if (!is_array($anchor)) {
            return null;
        }

        $exact = (isset($anchor['exact']) && is_string($anchor['exact']))
            ? $this->normalizeWhitespace($anchor['exact'])
            : '';
        if ($exact === '') {
            return null; // an anchor without quoted text is unusable
        }
        if (mb_strlen($exact) > self::MAX_QUOTE) {
            $exact = mb_substr($exact, 0, self::MAX_QUOTE);
        }

        $prefix = (isset($anchor['prefix']) && is_string($anchor['prefix']))
            ? $this->normalizeWhitespace($anchor['prefix'])
            : '';
        $suffix = (isset($anchor['suffix']) && is_string($anchor['suffix']))
            ? $this->normalizeWhitespace($anchor['suffix'])
            : '';
        $ctx = $this->contextLength();
        if (mb_strlen($prefix) > $ctx) {
            $prefix = mb_substr($prefix, -$ctx);
        }
        if (mb_strlen($suffix) > $ctx) {
            $suffix = mb_substr($suffix, 0, $ctx);
        }

        $start = isset($anchor['start']) ? max(0, (int) $anchor['start']) : 0;

        return [
            'exact'  => $exact,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'start'  => $start,
        ];
    }

    /**
     * Clean an annotation/reply body: a plain-text string, trimmed, with
     * normalised line endings and a length cap. Newlines are kept; the text
     * is escaped by the consumer at render time.
     *
     * @param mixed $body
     * @return string
     */
    protected function cleanBody($body)
    {
        if (!is_string($body)) {
            return '';
        }
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        $body = trim($body);
        $cap = $this->bodyCap();
        if (mb_strlen($body) > $cap) {
            $body = mb_substr($body, 0, $cap);
        }
        return $body;
    }

    /**
     * Collapse every run of whitespace to a single space and trim.
     *
     * @param mixed $text
     * @return string
     */
    protected function normalizeWhitespace($text)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * A fresh identifier for an annotation or reply.
     *
     * @return string 16 hex characters
     */
    protected function newId()
    {
        return bin2hex(random_bytes(8));
    }
}
