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

    /** length of the prefix/suffix context slices, in characters */
    const MAX_CONTEXT = 64;

    /** longest annotation/reply body, in characters */
    const MAX_BODY = 10000;

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
        $open = 0;
        $resolved = 0;
        foreach ($this->getAnnotations($id) as $a) {
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
        io_lock($file);

        $annotations = $this->getAnnotations($id);
        $outcome = $modifier($annotations);

        if ($outcome === false) {
            io_unlock($file);
            return false;
        }

        $ok = $this->writeFile($id, $annotations);
        io_unlock($file);
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
            $exact = $this->normalizeWhitespace($a['anchor']['exact'] ?? '');
            if ($exact === '' || mb_strpos($pageText, $exact) === false) {
                $orphaned[] = $a;
            }
        }
        return $orphaned;
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
        if (mb_strlen($prefix) > self::MAX_CONTEXT) {
            $prefix = mb_substr($prefix, -self::MAX_CONTEXT);
        }
        if (mb_strlen($suffix) > self::MAX_CONTEXT) {
            $suffix = mb_substr($suffix, 0, self::MAX_CONTEXT);
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
        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY);
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
