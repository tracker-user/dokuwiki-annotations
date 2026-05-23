/**
 * Annotations plugin — front-end script.
 *
 * Responsibilities:
 *
 *   1. BOOT: read JSINFO.annotations (injected by action.php); if the user
 *      has disabled annotations, exit early.
 *
 *   2. LOAD: fetch the page's annotation list via the AJAX endpoint, then:
 *        a. Anchor each annotation in the DOM (re-anchoring).
 *        b. Wrap matched text in highlight <span>s.
 *        c. Render per-line gutter markers.
 *        d. Update the page counter bubble.
 *
 *   3. SELECTION: detect when the user finishes a text selection inside the
 *      wiki content area, show an "Annotate" tooltip, capture the anchor on
 *      click, and open a new-annotation form.
 *
 *   4. PANELS: clicking a highlight opens the annotation thread inline, just
 *      below the paragraph that contains the highlight. One open panel at a
 *      time. The panel renders the full thread: author, timestamp, body,
 *      replies; and permission-gated action buttons.
 *
 *   5. AJAX: all state-changing operations POST JSON to
 *      /lib/exe/ajax.php?call=annotations (with the DokuWiki security token).
 *      Responses update the in-memory state and re-render affected highlights
 *      / gutter markers / counter without a page reload.
 *
 *   6. ORPHANS: annotations that cannot be re-anchored are counted and
 *      reachable via the orphaned counter link; their panels open in a
 *      dedicated orphan drawer at the bottom of the content area.
 *
 * FF78 ESR compatibility:
 *   - No #private fields, ??=, ||=, &&=, Array.at, structuredClone,
 *     Object.hasOwn, native <dialog>.
 *   - async/await, fetch, classes, ?., ??, Map/Set, IntersectionObserver OK.
 */

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    var AJAX_URL   = DOKU_BASE + 'lib/exe/ajax.php?call=annotations';
    var CONTENT_ID = 'dokuwiki__content';
    // .page is the article area inside #dokuwiki__content. Gutter markers
    // are appended here so position:relative doesn't break the sidebar nav.
    var PAGE_CLS = 'page';

    // Colour tokens (also defined in style.css; kept here so JS can read them)
    var CLS_HIGHLIGHT_OPEN     = 'ann-highlight-open';
    var CLS_HIGHLIGHT_RESOLVED = 'ann-highlight-resolved';
    var CLS_HIGHLIGHT_ORPHANED = 'ann-highlight-orphaned';
    var CLS_GUTTER_MARKER      = 'ann-gutter-marker';
    var CLS_PANEL              = 'ann-panel';
    var CLS_COUNTER            = 'ann-counter';
    var CLS_TOOLTIP            = 'ann-tooltip';
    var CLS_ORPHAN_DRAWER      = 'ann-orphan-drawer';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    /** All annotations fetched from the server, keyed by id. @type {Map<string,object>} */
    var _annotations = new Map();

    /** Currently open panel element, or null. @type {HTMLElement|null} */
    var _openPanel = null;

    /** ID of the annotation whose panel is open, or null. @type {string|null} */
    var _openAnnId = null;

    /** Current user info from JSINFO. @type {{pageId:string, enabled:bool}} */
    var _info = {};

    /** Lang strings (passed by PHP into JSINFO.annotations.lang). @type {object} */
    var _lang = {};

    /** The DokuWiki security token. @type {string} */
    var _token = '';

    /** Whether the current user is logged in. @type {bool} */
    var _loggedIn = false;

    /** Whether the current user is an admin. @type {bool} */
    var _isAdmin = false;

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    /**
     * Entry point: wired to DOMContentLoaded.
     */
    function boot() {
        var jsinfo = (typeof JSINFO !== 'undefined' && JSINFO) ? JSINFO : {};
        var annInfo = jsinfo.annotations || {};

        if (!annInfo.enabled) {
            return; // user disabled annotations
        }

        _info      = annInfo;
        _lang      = annInfo.lang || {};
        _token     = (typeof DOKU_XMLRPC !== 'undefined') ? '' : (jsinfo.token || '');

        // DokuWiki puts the security token in a hidden field on every page.
        var tokenField = document.getElementById('dw__token');
        if (tokenField) {
            _token = tokenField.value;
        }

        _loggedIn = !!(jsinfo.userinfo && jsinfo.userinfo.user);
        _isAdmin  = !!(jsinfo.userinfo && jsinfo.userinfo.isadmin);

        var content = document.getElementById(CONTENT_ID);
        if (!content) {
            return; // not a page view
        }

        renderCounter(annInfo.stats || {total: 0, open: 0, resolved: 0}, 0);
        loadAnnotations();
        initSelectionCapture(content);
    }

    // -----------------------------------------------------------------------
    // AJAX helpers
    // -----------------------------------------------------------------------

    /**
     * POST a JSON payload to the AJAX endpoint.
     *
     * @param {object} payload
     * @returns {Promise<object>} response data
     */
    function ajax(payload) {
        payload.sectok = _token; // DokuWiki security token field name for AJAX
        return fetch(AJAX_URL, {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify(payload),
        }).then(function (res) {
            return res.json();
        });
    }

    // -----------------------------------------------------------------------
    // Load and anchor annotations
    // -----------------------------------------------------------------------

    /**
     * Fetch all annotations for the current page and render them.
     */
    function loadAnnotations() {
        // We use a lightweight GET-style call: the action.php AJAX handler
        // is POST-only, so we pass action=load in the payload.
        fetch(AJAX_URL + '&action=load&id=' + encodeURIComponent(_info.pageId), {
            method: 'GET',
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !Array.isArray(data.annotations)) {
                return;
            }
            data.annotations.forEach(function (ann) {
                _annotations.set(ann.id, ann);
            });
            renderAll();
        }).catch(function () {
            // Graceful degradation: page still works without annotations.
        });
    }

    /**
     * Re-render everything: highlights, gutter markers, counter.
     */
    function renderAll() {
        clearHighlights();
        clearGutterMarkers();

        var content = document.getElementById(CONTENT_ID);
        if (!content) return;

        var orphanCount = 0;

        _annotations.forEach(function (ann) {
            var range = findRange(content, ann.anchor);
            ann._range   = range; // cache for panel positioning
            ann._orphaned = !range;

            if (range) {
                wrapHighlight(range, ann);
            } else {
                orphanCount++;
            }
        });

        renderGutterMarkers();
        updateCounter(orphanCount);
    }

    // -----------------------------------------------------------------------
    // Text anchoring (re-anchoring)
    // -----------------------------------------------------------------------

    /**
     * Find the DOM Range for an anchor's quoted text.
     *
     * Algorithm:
     *   1. Collect the page text via TreeWalker.
     *   2. Search for the exact quote (normalised).
     *   3. If found multiple times, use prefix/suffix to disambiguate.
     *   4. If still ambiguous, use the start offset hint.
     *   5. Map the character offset back to a DOM Range.
     *
     * @param {HTMLElement} root
     * @param {object}      anchor  {exact, prefix, suffix, start}
     * @returns {Range|null}
     */
    function findRange(root, anchor) {
        if (!anchor || !anchor.exact) return null;

        var exact  = normalizeWS(anchor.exact);
        var prefix = normalizeWS(anchor.prefix || '');
        var suffix = normalizeWS(anchor.suffix || '');
        var hint   = anchor.start || 0;

        if (exact === '') return null;

        // Collect all text nodes in document order with their cumulative offsets.
        var chunks = collectTextChunks(root);
        var fullText = chunks.map(function (c) { return c.text; }).join('');
        fullText = normalizeWS(fullText);

        // Find all occurrences of exact.
        var positions = [];
        var search = fullText;
        var base   = 0;
        var idx;
        while ((idx = search.indexOf(exact)) !== -1) {
            positions.push(base + idx);
            base   += idx + exact.length;
            search  = search.slice(idx + exact.length);
        }

        if (positions.length === 0) return null;

        var chosenPos = positions[0];

        if (positions.length > 1) {
            // Disambiguate using prefix + suffix context.
            var best = null;
            var bestScore = -1;
            positions.forEach(function (pos) {
                var pre = fullText.slice(Math.max(0, pos - prefix.length), pos);
                var suf = fullText.slice(pos + exact.length, pos + exact.length + suffix.length);
                var score = 0;
                if (prefix && pre.indexOf(prefix) !== -1) score++;
                if (suffix && suf.indexOf(suffix) !== -1) score++;
                // Use start offset as tiebreaker.
                var distToHint = Math.abs(pos - hint);
                if (score > bestScore || (score === bestScore && distToHint < Math.abs(chosenPos - hint))) {
                    bestScore = score;
                    best      = pos;
                    chosenPos = pos;
                }
            });
            if (best !== null) chosenPos = best;
        }

        return buildRange(chunks, chosenPos, exact.length);
    }

    /**
     * Walk the text nodes under root and return an array of
     * {node, start, text} objects where start is the cumulative character
     * offset of this node's text in the joined string.
     *
     * The joined string is NOT normalised here — we normalise the full string
     * once above instead.
     *
     * @param {HTMLElement} root
     * @returns {Array<{node:Text, start:number, text:string}>}
     */
    function collectTextChunks(root) {
        var walker = document.createTreeWalker(
            root,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        var chunks = [];
        var offset = 0;
        var node;
        while ((node = walker.nextNode())) {
            // Skip nodes inside our own UI elements.
            if (isAnnotationUI(node.parentNode)) continue;
            var text = node.nodeValue || '';
            chunks.push({node: node, start: offset, text: text});
            offset += text.length;
        }
        return chunks;
    }

    /**
     * True if the element (or its ancestor) is part of our annotation UI.
     *
     * @param {Node} el
     * @returns {bool}
     */
    function isAnnotationUI(el) {
        while (el && el !== document.body) {
            if (el.nodeType === 1) {
                var cls = el.className || '';
                if (
                    cls.indexOf('ann-') !== -1 ||
                    cls.indexOf(CLS_PANEL) !== -1
                ) {
                    return true;
                }
            }
            el = el.parentNode;
        }
        return false;
    }

    /**
     * Turn character offsets (in the normalised full string) back into a
     * DOM Range.
     *
     * @param {Array<{node:Text, start:number, text:string}>} chunks
     * @param {number} startOff  start char offset in joined (raw) text
     * @param {number} length    length of selection in normalised text
     * @returns {Range|null}
     */
    function buildRange(chunks, startOff, length) {
        // The fullText we searched is normalised (multiple spaces → one), but
        // chunk offsets are raw. We need to find the raw offset that corresponds
        // to startOff in the normalised string.
        //
        // Simple approach: walk chunks until we've "consumed" startOff
        // normalised characters (counting consecutive spaces as 1).
        // This works well enough for typical wiki prose.

        var rawFull  = chunks.map(function (c) { return c.text; }).join('');
        var normFull = normalizeWS(rawFull);

        // Build a map: normFull[i] → rawFull[j]
        var normToRaw = buildNormToRaw(rawFull);

        var rawStart = normToRaw[startOff];
        var rawEnd   = normToRaw[startOff + length - 1];
        if (rawStart === undefined || rawEnd === undefined) return null;
        rawEnd++; // exclusive

        // Find which chunks contain rawStart and rawEnd.
        var startChunk = null, startOffset = 0;
        var endChunk   = null, endOffset   = 0;

        for (var i = 0; i < chunks.length; i++) {
            var c = chunks[i];
            var cEnd = c.start + c.text.length;

            if (startChunk === null && c.start <= rawStart && rawStart < cEnd) {
                startChunk  = c.node;
                startOffset = rawStart - c.start;
            }
            if (endChunk === null && c.start < rawEnd && rawEnd <= cEnd) {
                endChunk  = c.node;
                endOffset = rawEnd - c.start;
            }
            if (startChunk && endChunk) break;
        }

        if (!startChunk || !endChunk) return null;

        try {
            var range = document.createRange();
            range.setStart(startChunk, startOffset);
            range.setEnd(endChunk, endOffset);
            return range;
        } catch (e) {
            return null;
        }
    }

    /**
     * Build an array mapping normalised-string index → raw-string index.
     * Consecutive whitespace is collapsed to a single space; the mapping
     * records the index of the first character in each run.
     *
     * @param {string} raw
     * @returns {Array<number>}
     */
    function buildNormToRaw(raw) {
        var map     = [];
        var inSpace = false;
        for (var i = 0; i < raw.length; i++) {
            var ch = raw[i];
            if (/\s/.test(ch)) {
                if (!inSpace) {
                    map.push(i); // one representative space
                    inSpace = true;
                }
                // else: extra whitespace chars are skipped
            } else {
                map.push(i);
                inSpace = false;
            }
        }
        return map;
    }

    // -----------------------------------------------------------------------
    // Highlights
    // -----------------------------------------------------------------------

    /**
     * Wrap a Range in a highlight <span> for the given annotation.
     *
     * @param {Range}  range
     * @param {object} ann
     */
    function wrapHighlight(range, ann) {
        try {
            var span = document.createElement('span');
            span.className = ann.status === 'resolved'
                ? CLS_HIGHLIGHT_RESOLVED
                : CLS_HIGHLIGHT_OPEN;
            span.dataset.annId = ann.id;
            span.title = ann.body.slice(0, 80) + (ann.body.length > 80 ? '…' : '');
            span.addEventListener('click', function (e) {
                e.stopPropagation();
                openPanel(ann.id);
            });
            range.surroundContents(span);
            ann._highlightEl = span;
        } catch (e) {
            // surroundContents throws if the range crosses element boundaries.
            // Fall back to insertNode with a cloned range fragment.
            try {
                var frag = range.extractContents();
                var span2 = document.createElement('span');
                span2.className = ann.status === 'resolved'
                    ? CLS_HIGHLIGHT_RESOLVED
                    : CLS_HIGHLIGHT_OPEN;
                span2.dataset.annId = ann.id;
                span2.appendChild(frag);
                span2.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openPanel(ann.id);
                });
                range.insertNode(span2);
                ann._highlightEl = span2;
            } catch (e2) {
                ann._highlightEl = null;
            }
        }
    }

    /**
     * Remove all highlight spans, restoring the original text nodes.
     */
    function clearHighlights() {
        var spans = document.querySelectorAll(
            '.' + CLS_HIGHLIGHT_OPEN + ', .' + CLS_HIGHLIGHT_RESOLVED + ', .' + CLS_HIGHLIGHT_ORPHANED
        );
        Array.prototype.forEach.call(spans, function (span) {
            var parent = span.parentNode;
            if (!parent) return;
            while (span.firstChild) {
                parent.insertBefore(span.firstChild, span);
            }
            parent.removeChild(span);
            parent.normalize();
        });
    }

    // -----------------------------------------------------------------------
    // Gutter markers
    // -----------------------------------------------------------------------

    /**
     * Render a small marker in the gutter for every anchored annotation.
     * Markers are absolutely positioned relative to the content wrapper.
     */
    function renderGutterMarkers() {
        // Append markers to .page (position:relative), not #dokuwiki__content
        // (which also wraps the sidebar nav and would capture pointer events).
        var pageEl = document.querySelector('.' + PAGE_CLS);
        if (!pageEl) return;

        _annotations.forEach(function (ann) {
            if (!ann._highlightEl) return; // orphan

            var el      = ann._highlightEl;
            var rect    = el.getBoundingClientRect();
            var pageRect = pageEl.getBoundingClientRect();

            var marker = document.createElement('button');
            marker.className  = CLS_GUTTER_MARKER;
            marker.dataset.annId = ann.id;
            marker.setAttribute('aria-label', 'Annotation');
            marker.type = 'button';
            // top is relative to .page's top edge + its current scroll offset
            marker.style.top = (rect.top - pageRect.top + pageEl.scrollTop) + 'px';
            marker.addEventListener('click', function (e) {
                e.stopPropagation();
                openPanel(ann.id);
            });
            pageEl.appendChild(marker);
            ann._markerEl = marker;
        });
    }

    /**
     * Remove all gutter markers.
     */
    function clearGutterMarkers() {
        var markers = document.querySelectorAll('.' + CLS_GUTTER_MARKER);
        Array.prototype.forEach.call(markers, function (m) {
            if (m.parentNode) m.parentNode.removeChild(m);
        });
    }

    // -----------------------------------------------------------------------
    // Page counter
    // -----------------------------------------------------------------------

    /**
     * Render (or update) the counter bubble above the content area.
     *
     * @param {object} stats        {total, open, resolved}
     * @param {number} orphanCount
     */
    function renderCounter(stats, orphanCount) {
        var existing = document.getElementById('ann-counter-bar');
        if (existing) existing.parentNode.removeChild(existing);

        if (stats.total === 0 && orphanCount === 0) return;

        var bar = document.createElement('div');
        bar.id = 'ann-counter-bar';
        bar.className = CLS_COUNTER;

        var total = stats.total || 0;
        var label = total === 1
            ? '1 annotation'
            : total + ' annotations';
        bar.appendChild(document.createTextNode(label));

        if (orphanCount > 0) {
            bar.appendChild(document.createTextNode(' · '));
            var orphanLink = document.createElement('a');
            orphanLink.href = '#ann-orphan-drawer';
            orphanLink.className = 'ann-orphan-link';
            orphanLink.textContent = orphanCount + ' orphaned';
            orphanLink.addEventListener('click', function (e) {
                e.preventDefault();
                toggleOrphanDrawer();
            });
            bar.appendChild(orphanLink);
        }

        if (_isAdmin && (stats.resolved > 0 || orphanCount > 0)) {
            if (stats.resolved > 0) {
                var btnCR = document.createElement('button');
                btnCR.type = 'button';
                btnCR.className = 'ann-btn ann-btn-admin';
                btnCR.textContent = 'Clear resolved';
                btnCR.addEventListener('click', doClearResolved);
                bar.appendChild(btnCR);
            }
            if (orphanCount > 0) {
                var btnCO = document.createElement('button');
                btnCO.type = 'button';
                btnCO.className = 'ann-btn ann-btn-admin';
                btnCO.textContent = 'Clear orphaned';
                btnCO.addEventListener('click', doClearOrphaned);
                bar.appendChild(btnCO);
            }
        }

        var content = document.getElementById(CONTENT_ID);
        if (content && content.parentNode) {
            content.parentNode.insertBefore(bar, content);
        }
    }

    /**
     * Recount and re-render the counter from in-memory state.
     */
    function updateCounter(orphanCount) {
        var open = 0, resolved = 0;
        if (orphanCount === undefined) {
            orphanCount = 0;
        }
        _annotations.forEach(function (ann) {
            if (ann._orphaned) {
                orphanCount++;
            } else if (ann.status === 'resolved') {
                resolved++;
            } else {
                open++;
            }
        });
        renderCounter({total: open + resolved, open: open, resolved: resolved}, orphanCount);
    }

    // -----------------------------------------------------------------------
    // Annotation panel
    // -----------------------------------------------------------------------

    /**
     * Open the thread panel for the given annotation id.
     * If that panel is already open, close it.
     *
     * @param {string} annId
     */
    function openPanel(annId) {
        if (_openAnnId === annId) {
            closePanel();
            return;
        }
        closePanel();

        var ann = _annotations.get(annId);
        if (!ann) return;

        var panel = buildPanel(ann);
        _openPanel  = panel;
        _openAnnId  = annId;

        // Insert below the paragraph that contains the highlight.
        var anchor = ann._highlightEl || null;
        var insertAfter = findParagraph(anchor);
        if (insertAfter && insertAfter.parentNode) {
            insertAfter.parentNode.insertBefore(panel, insertAfter.nextSibling);
        } else {
            // Orphan or no paragraph found: show at the bottom of content.
            var content = document.getElementById(CONTENT_ID);
            if (content) content.appendChild(panel);
        }

        panel.querySelector('.ann-body-input') && panel.querySelector('.ann-body-input').focus();
    }

    /**
     * Close and remove the currently open panel.
     */
    function closePanel() {
        if (_openPanel && _openPanel.parentNode) {
            _openPanel.parentNode.removeChild(_openPanel);
        }
        _openPanel = null;
        _openAnnId = null;
    }

    /**
     * Find the nearest block-level ancestor of el (p, li, h1-h6, etc.)
     * that can receive a sibling element.
     *
     * @param {HTMLElement|null} el
     * @returns {HTMLElement|null}
     */
    function findParagraph(el) {
        var block = /^(P|LI|DT|DD|H[1-6]|BLOCKQUOTE|PRE|TABLE|DIV)$/;
        var node = el;
        while (node && node.id !== CONTENT_ID) {
            if (node.nodeType === 1 && block.test(node.tagName)) {
                return node;
            }
            node = node.parentNode;
        }
        return el; // fallback: use the element itself
    }

    /**
     * Build and return the panel DOM element for one annotation.
     *
     * @param {object} ann
     * @returns {HTMLElement}
     */
    function buildPanel(ann) {
        var panel = document.createElement('div');
        panel.className = CLS_PANEL;
        panel.dataset.annId = ann.id;

        // Header
        var header = document.createElement('div');
        header.className = 'ann-panel-header';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'ann-btn ann-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', closePanel);
        header.appendChild(closeBtn);

        panel.appendChild(header);

        // Main annotation thread entry
        panel.appendChild(buildThreadEntry(ann, true));

        // Replies
        (ann.replies || []).forEach(function (reply) {
            panel.appendChild(buildReplyEntry(ann, reply));
        });

        // Reply form (if logged in and has read access — gate is server-side anyway)
        if (_loggedIn) {
            panel.appendChild(buildReplyForm(ann));
        }

        return panel;
    }

    /**
     * Build the DOM for the top-level annotation entry.
     *
     * @param {object}  ann
     * @param {boolean} isRoot  true for the annotation itself, false for replies
     * @returns {HTMLElement}
     */
    function buildThreadEntry(ann, isRoot) {
        var entry = document.createElement('div');
        entry.className = 'ann-thread-entry ann-annotation';
        entry.dataset.annId = ann.id;

        // Meta row: avatar, author, time, status pill
        entry.appendChild(buildMeta(ann.author, ann.created, ann.status));

        // Body
        var bodyEl = document.createElement('div');
        bodyEl.className = 'ann-body';
        bodyEl.textContent = ann.body;
        entry.appendChild(bodyEl);

        // Quoted text snippet
        if (ann.anchor && ann.anchor.exact) {
            var quote = document.createElement('blockquote');
            quote.className = 'ann-quote';
            quote.textContent = ann.anchor.exact;
            entry.appendChild(quote);
        }

        // Action buttons
        var actions = document.createElement('div');
        actions.className = 'ann-actions';

        // Resolve/Reopen (any reader)
        if (_loggedIn) {
            var resolveBtn = document.createElement('button');
            resolveBtn.type = 'button';
            resolveBtn.className = 'ann-btn ann-btn-resolve';
            resolveBtn.textContent = ann.status === 'resolved' ? 'Reopen' : 'Resolve';
            resolveBtn.addEventListener('click', function () {
                doResolve(ann.id, ann.status === 'resolved' ? 'open' : 'resolved');
            });
            actions.appendChild(resolveBtn);
        }

        // Edit + Delete (own or admin)
        var canEdit = _isAdmin || ann.author === currentUser();
        if (canEdit && _loggedIn) {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'ann-btn';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', function () {
                showEditForm(entry, ann, 'annotation');
            });
            actions.appendChild(editBtn);

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'ann-btn ann-btn-danger';
            delBtn.textContent = 'Delete';
            delBtn.addEventListener('click', function () {
                if (confirm('Delete this annotation?')) {
                    doDeleteAnnotation(ann.id);
                }
            });
            actions.appendChild(delBtn);
        }

        entry.appendChild(actions);
        return entry;
    }

    /**
     * Build the DOM for one reply entry.
     *
     * @param {object} ann   parent annotation
     * @param {object} reply
     * @returns {HTMLElement}
     */
    function buildReplyEntry(ann, reply) {
        var entry = document.createElement('div');
        entry.className = 'ann-thread-entry ann-reply';
        entry.dataset.replyId = reply.id;

        entry.appendChild(buildMeta(reply.author, reply.created, null));

        var bodyEl = document.createElement('div');
        bodyEl.className = 'ann-body';
        bodyEl.textContent = reply.body;
        entry.appendChild(bodyEl);

        var actions = document.createElement('div');
        actions.className = 'ann-actions';

        var canEdit = _isAdmin || reply.author === currentUser();
        if (canEdit && _loggedIn) {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'ann-btn';
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', function () {
                showEditForm(entry, {annId: ann.id, replyId: reply.id, body: reply.body}, 'reply');
            });
            actions.appendChild(editBtn);

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'ann-btn ann-btn-danger';
            delBtn.textContent = 'Delete';
            delBtn.addEventListener('click', function () {
                if (confirm('Delete this reply?')) {
                    doDeleteReply(ann.id, reply.id);
                }
            });
            actions.appendChild(delBtn);
        }

        entry.appendChild(actions);
        return entry;
    }

    /**
     * Build the meta row (avatar initials, author name, timestamp, status pill).
     *
     * @param {string}      author
     * @param {number}      timestamp  Unix seconds
     * @param {string|null} status     'open'|'resolved'|null
     * @returns {HTMLElement}
     */
    function buildMeta(author, timestamp, status) {
        var meta = document.createElement('div');
        meta.className = 'ann-meta';

        var avatar = document.createElement('span');
        avatar.className = 'ann-avatar';
        avatar.textContent = (author || '?').slice(0, 2).toUpperCase();
        meta.appendChild(avatar);

        var authorEl = document.createElement('span');
        authorEl.className = 'ann-author';
        authorEl.textContent = author || 'Unknown';
        meta.appendChild(authorEl);

        var timeEl = document.createElement('time');
        timeEl.className = 'ann-time';
        var d = new Date(timestamp * 1000);
        timeEl.dateTime = d.toISOString();
        timeEl.textContent = formatDate(d);
        meta.appendChild(timeEl);

        if (status) {
            var pill = document.createElement('span');
            pill.className = 'ann-status ann-status-' + status;
            pill.textContent = status === 'resolved' ? 'Resolved' : 'Open';
            meta.appendChild(pill);
        }

        return meta;
    }

    /**
     * Build a reply form at the bottom of the panel.
     *
     * @param {object} ann
     * @returns {HTMLElement}
     */
    function buildReplyForm(ann) {
        var form = document.createElement('div');
        form.className = 'ann-reply-form';

        var ta = document.createElement('textarea');
        ta.className = 'ann-body-input';
        ta.placeholder = 'Write a reply…';
        ta.rows = 3;
        form.appendChild(ta);

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'ann-btn ann-btn-primary';
        submitBtn.textContent = 'Reply';
        submitBtn.addEventListener('click', function () {
            var body = ta.value.trim();
            if (!body) return;
            doAddReply(ann.id, body, function () {
                ta.value = '';
            });
        });
        row.appendChild(submitBtn);
        form.appendChild(row);

        return form;
    }

    /**
     * Replace the body of an entry with an inline edit form.
     *
     * @param {HTMLElement} entry
     * @param {object}      data    {body, annId?, replyId?}  (annId = undefined → annotation)
     * @param {string}      type    'annotation' | 'reply'
     */
    function showEditForm(entry, data, type) {
        var bodyEl = entry.querySelector('.ann-body');
        if (!bodyEl) return;

        var ta = document.createElement('textarea');
        ta.className = 'ann-body-input';
        ta.value = data.body || '';
        ta.rows  = 4;

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'ann-btn ann-btn-primary';
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', function () {
            var newBody = ta.value.trim();
            if (!newBody) return;
            if (type === 'annotation') {
                doEditAnnotation(data.id || _openAnnId, newBody);
            } else {
                doEditReply(data.annId, data.replyId, newBody);
            }
        });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'ann-btn';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', function () {
            entry.removeChild(ta);
            entry.removeChild(row);
            bodyEl.style.display = '';
        });

        row.appendChild(saveBtn);
        row.appendChild(cancelBtn);

        bodyEl.style.display = 'none';
        entry.insertBefore(ta, bodyEl.nextSibling);
        entry.insertBefore(row, ta.nextSibling);
        ta.focus();
    }

    // -----------------------------------------------------------------------
    // Orphan drawer
    // -----------------------------------------------------------------------

    /**
     * Toggle the orphan drawer visibility.
     */
    function toggleOrphanDrawer() {
        var drawer = document.getElementById('ann-orphan-drawer');
        if (drawer) {
            drawer.parentNode.removeChild(drawer);
            return;
        }
        renderOrphanDrawer();
    }

    /**
     * Build and insert the orphan drawer at the bottom of the content area.
     */
    function renderOrphanDrawer() {
        var content = document.getElementById(CONTENT_ID);
        if (!content) return;

        var drawer = document.createElement('div');
        drawer.id = 'ann-orphan-drawer';
        drawer.className = CLS_ORPHAN_DRAWER;

        var heading = document.createElement('h4');
        heading.textContent = 'Orphaned annotations';
        drawer.appendChild(heading);

        var note = document.createElement('p');
        note.className = 'ann-orphan-note';
        note.textContent = 'These annotations reference text that no longer appears on the page.';
        drawer.appendChild(note);

        var found = false;
        _annotations.forEach(function (ann) {
            if (!ann._orphaned) return;
            found = true;
            var entry = buildThreadEntry(ann, true);
            drawer.appendChild(entry);
        });

        if (!found) {
            var empty = document.createElement('p');
            empty.textContent = 'None.';
            drawer.appendChild(empty);
        }

        content.appendChild(drawer);
    }

    // -----------------------------------------------------------------------
    // Selection capture
    // -----------------------------------------------------------------------

    /**
     * Wire up mouseup/touchend listeners to detect text selection.
     *
     * @param {HTMLElement} content
     */
    function initSelectionCapture(content) {
        if (!_loggedIn) return; // anonymous users cannot annotate

        document.addEventListener('mouseup', function (e) {
            handleSelectionEnd(e, content);
        });
        document.addEventListener('touchend', function (e) {
            // Small delay so the browser has committed the selection.
            setTimeout(function () { handleSelectionEnd(e, content); }, 50);
        });

        // Close tooltip/panel on click outside.
        document.addEventListener('mousedown', function (e) {
            var tooltip = document.getElementById('ann-tooltip');
            if (tooltip && !tooltip.contains(e.target)) {
                hideTooltip();
            }
        });
    }

    /**
     * Handle end of selection: show the "Annotate" tooltip if there is a
     * non-empty selection inside the content area.
     *
     * @param {Event}       e
     * @param {HTMLElement} content
     */
    function handleSelectionEnd(e, content) {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed) {
            hideTooltip();
            return;
        }
        var range = sel.getRangeAt(0);
        if (!content.contains(range.commonAncestorContainer)) {
            hideTooltip();
            return;
        }
        var text = sel.toString().trim();
        if (text.length < 1) {
            hideTooltip();
            return;
        }

        // Show the tooltip near the end of the selection.
        var rect = range.getBoundingClientRect();
        showTooltip(rect, range, sel, content);
    }

    /**
     * Show the "Annotate" tooltip bubble.
     *
     * @param {DOMRect}     rect     bounding rect of the selection
     * @param {Range}       range
     * @param {Selection}   sel
     * @param {HTMLElement} content
     */
    function showTooltip(rect, range, sel, content) {
        hideTooltip();

        var tip = document.createElement('div');
        tip.id = 'ann-tooltip';
        tip.className = CLS_TOOLTIP;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Annotate';
        btn.className = 'ann-btn ann-btn-primary';
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault(); // don't lose the selection
        });
        btn.addEventListener('click', function () {
            var anchor = captureAnchor(sel, range, content);
            hideTooltip();
            sel.removeAllRanges();
            if (anchor) {
                openNewAnnotationForm(anchor, range);
            }
        });
        tip.appendChild(btn);

        document.body.appendChild(tip);

        // Position below the selection's end.
        var scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        tip.style.top  = (rect.bottom + scrollTop  + 6) + 'px';
        tip.style.left = (rect.left   + scrollLeft)     + 'px';
    }

    /**
     * Remove the tooltip if it exists.
     */
    function hideTooltip() {
        var tip = document.getElementById('ann-tooltip');
        if (tip && tip.parentNode) {
            tip.parentNode.removeChild(tip);
        }
        // Also remove any floating new-annotation form.
        var naf = document.getElementById('ann-new-form');
        if (naf && naf.parentNode) {
            naf.parentNode.removeChild(naf);
        }
    }

    /**
     * Capture an anchor object from the current Selection.
     *
     * @param {Selection}   sel
     * @param {Range}       range
     * @param {HTMLElement} content
     * @returns {object|null} {exact, prefix, suffix, start}
     */
    function captureAnchor(sel, range, content) {
        var exact = normalizeWS(sel.toString());
        if (!exact) return null;

        // Get full page text for prefix/suffix and start computation.
        var chunks   = collectTextChunks(content);
        var fullRaw  = chunks.map(function (c) { return c.text; }).join('');
        var fullNorm = normalizeWS(fullRaw);

        // Find where this text node + offset lands in the raw full text.
        var rawStart = 0;
        for (var i = 0; i < chunks.length; i++) {
            var c = chunks[i];
            if (c.node === range.startContainer) {
                rawStart = c.start + range.startOffset;
                break;
            }
        }

        // Map raw offset to normalised offset.
        var normToRaw = buildNormToRaw(fullRaw);
        var normStart = 0;
        for (var j = 0; j < normToRaw.length; j++) {
            if (normToRaw[j] >= rawStart) {
                normStart = j;
                break;
            }
        }

        var CTX = 30;
        var prefix = fullNorm.slice(Math.max(0, normStart - CTX), normStart);
        var suffix = fullNorm.slice(normStart + exact.length, normStart + exact.length + CTX);

        return {
            exact:  exact,
            prefix: prefix,
            suffix: suffix,
            start:  normStart,
        };
    }

    /**
     * Open the new-annotation form below the paragraph containing the selection.
     *
     * @param {object} anchor  {exact, prefix, suffix, start}
     * @param {Range}  range
     */
    function openNewAnnotationForm(anchor, range) {
        closePanel();

        var insertAfter = findParagraph(range.commonAncestorContainer);
        var form = document.createElement('div');
        form.id = 'ann-new-form';
        form.className = 'ann-new-form';

        var quote = document.createElement('blockquote');
        quote.className = 'ann-quote';
        quote.textContent = anchor.exact;
        form.appendChild(quote);

        var ta = document.createElement('textarea');
        ta.className = 'ann-body-input';
        ta.placeholder = 'Add a comment…';
        ta.rows = 4;
        form.appendChild(ta);

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'ann-btn ann-btn-primary';
        submitBtn.textContent = 'Annotate';
        submitBtn.addEventListener('click', function () {
            var body = ta.value.trim();
            if (!body) return;
            doCreate(anchor, body, function () {
                if (form.parentNode) form.parentNode.removeChild(form);
            });
        });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'ann-btn';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', function () {
            if (form.parentNode) form.parentNode.removeChild(form);
        });

        row.appendChild(submitBtn);
        row.appendChild(cancelBtn);
        form.appendChild(row);

        if (insertAfter && insertAfter.parentNode) {
            insertAfter.parentNode.insertBefore(form, insertAfter.nextSibling);
        } else {
            var content = document.getElementById(CONTENT_ID);
            if (content) content.appendChild(form);
        }

        ta.focus();
    }

    // -----------------------------------------------------------------------
    // AJAX actions
    // -----------------------------------------------------------------------

    /**
     * POST create action and update state on success.
     *
     * @param {object}   anchor
     * @param {string}   body
     * @param {Function} onSuccess
     */
    function doCreate(anchor, body, onSuccess) {
        ajax({
            action: 'create',
            id:     _info.pageId,
            anchor: anchor,
            body:   body,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not save annotation: ' + (data.error || 'Unknown error'));
                return;
            }
            var ann = data.annotation;
            _annotations.set(ann.id, ann);
            if (typeof onSuccess === 'function') onSuccess(ann);
            renderAll();
        }).catch(function () {
            alert('Could not save annotation.');
        });
    }

    /**
     * POST reply action and refresh the open panel.
     *
     * @param {string}   annId
     * @param {string}   body
     * @param {Function} onSuccess
     */
    function doAddReply(annId, body, onSuccess) {
        ajax({
            action: 'reply',
            id:     _info.pageId,
            annId:  annId,
            body:   body,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not save reply: ' + (data.error || ''));
                return;
            }
            // Re-fetch the updated annotation from server.
            refreshAnnotation(annId, function () {
                if (typeof onSuccess === 'function') onSuccess();
                reopenPanel(annId);
            });
        }).catch(function () {
            alert('Could not save reply.');
        });
    }

    /**
     * POST edit_annotation and re-render.
     *
     * @param {string} annId
     * @param {string} body
     */
    function doEditAnnotation(annId, body) {
        ajax({
            action: 'edit_annotation',
            id:     _info.pageId,
            annId:  annId,
            body:   body,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not save: ' + (data.error || ''));
                return;
            }
            var updated = data.annotation;
            _annotations.set(updated.id, updated);
            reopenPanel(annId);
        });
    }

    /**
     * POST edit_reply and re-render.
     *
     * @param {string} annId
     * @param {string} replyId
     * @param {string} body
     */
    function doEditReply(annId, replyId, body) {
        ajax({
            action:   'edit_reply',
            id:       _info.pageId,
            annId:    annId,
            replyId:  replyId,
            body:     body,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not save: ' + (data.error || ''));
                return;
            }
            var updated = data.annotation;
            _annotations.set(updated.id, updated);
            reopenPanel(annId);
        });
    }

    /**
     * POST delete_annotation.
     *
     * @param {string} annId
     */
    function doDeleteAnnotation(annId) {
        ajax({
            action: 'delete_annotation',
            id:     _info.pageId,
            annId:  annId,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not delete: ' + (data.error || ''));
                return;
            }
            _annotations.delete(annId);
            closePanel();
            renderAll();
        });
    }

    /**
     * POST delete_reply and re-render.
     *
     * @param {string} annId
     * @param {string} replyId
     */
    function doDeleteReply(annId, replyId) {
        ajax({
            action:  'delete_reply',
            id:      _info.pageId,
            annId:   annId,
            replyId: replyId,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not delete: ' + (data.error || ''));
                return;
            }
            var updated = data.annotation;
            _annotations.set(updated.id, updated);
            reopenPanel(annId);
        });
    }

    /**
     * POST resolve/reopen action.
     *
     * @param {string} annId
     * @param {string} status  'open' | 'resolved'
     */
    function doResolve(annId, status) {
        ajax({
            action: 'resolve',
            id:     _info.pageId,
            annId:  annId,
            status: status,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not update status: ' + (data.error || ''));
                return;
            }
            var updated = data.annotation;
            _annotations.set(updated.id, updated);
            renderAll();
            reopenPanel(annId);
        });
    }

    /**
     * POST clear_resolved (admin).
     */
    function doClearResolved() {
        if (!confirm('Delete all resolved annotations on this page?')) return;
        ajax({
            action: 'clear_resolved',
            id:     _info.pageId,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not clear: ' + (data.error || ''));
                return;
            }
            // Remove resolved from local state.
            _annotations.forEach(function (ann, id) {
                if (ann.status === 'resolved') _annotations.delete(id);
            });
            closePanel();
            renderAll();
        });
    }

    /**
     * POST clear_orphaned (admin).
     */
    function doClearOrphaned() {
        if (!confirm('Delete all orphaned annotations on this page?')) return;
        ajax({
            action: 'clear_orphaned',
            id:     _info.pageId,
        }).then(function (data) {
            if (!data.success) {
                alert('Could not clear: ' + (data.error || ''));
                return;
            }
            _annotations.forEach(function (ann, id) {
                if (ann._orphaned) _annotations.delete(id);
            });
            closePanel();
            renderAll();
        });
    }

    // -----------------------------------------------------------------------
    // Panel management helpers
    // -----------------------------------------------------------------------

    /**
     * Re-fetch one annotation from the server and update local state.
     *
     * Note: the AJAX endpoint doesn't have a standalone "get one" action,
     * so we ask the load endpoint (GET) and pull the matching entry out.
     *
     * @param {string}   annId
     * @param {Function} cb
     */
    function refreshAnnotation(annId, cb) {
        fetch(AJAX_URL + '&action=load&id=' + encodeURIComponent(_info.pageId), {
            method: 'GET',
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (data && Array.isArray(data.annotations)) {
                data.annotations.forEach(function (ann) {
                    _annotations.set(ann.id, ann);
                });
            }
            if (typeof cb === 'function') cb();
        }).catch(function () {
            if (typeof cb === 'function') cb();
        });
    }

    /**
     * Close the current panel and re-open it (preserves scroll position and
     * re-renders the thread with fresh data).
     *
     * @param {string} annId
     */
    function reopenPanel(annId) {
        closePanel();
        openPanel(annId);
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Collapse consecutive whitespace to a single space and trim.
     *
     * @param {string} s
     * @returns {string}
     */
    function normalizeWS(s) {
        return String(s || '').replace(/\s+/g, ' ').trim();
    }

    /**
     * Return the current DokuWiki username from JSINFO.
     *
     * @returns {string}
     */
    function currentUser() {
        var jsinfo = (typeof JSINFO !== 'undefined' && JSINFO) ? JSINFO : {};
        return (jsinfo.userinfo && jsinfo.userinfo.user) ? jsinfo.userinfo.user : '';
    }

    /**
     * Format a Date for display.
     *
     * @param {Date} d
     * @returns {string}
     */
    function formatDate(d) {
        var now  = new Date();
        var diff = (now - d) / 1000; // seconds
        if (diff < 60)              return 'just now';
        if (diff < 3600)            return Math.floor(diff / 60)   + 'm ago';
        if (diff < 86400)           return Math.floor(diff / 3600) + 'h ago';
        if (diff < 86400 * 7)       return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString();
    }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

}());
