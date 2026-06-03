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

    /** Anchor captured on tooltip button mousedown; consumed by click. @type {object|null} */
    var _pendingAnchor = null;

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
        // UI strings come from DokuWiki's per-plugin JS lang bundle, exposed as
        // LANG.plugins.annotations (built from lang/<iso>/lang.php $lang['js']).
        _lang      = uiLang();
        // Token is injected into JSINFO.annotations by action.php (handleMetaHeader).
        // getSecurityToken() on the server produces it from session_id + REMOTE_USER.
        _token     = annInfo.token || '';

        // DokuWiki's JSINFO doesn't include user identity; we inject
        // user + isAdmin into JSINFO.annotations from PHP (action.php).
        _loggedIn = !!(annInfo.user && annInfo.user !== '');
        _isAdmin  = !!(annInfo.isAdmin);

        var content = document.getElementById(CONTENT_ID);
        if (!content) {
            return; // not a page view
        }

        renderCounter(annInfo.stats || {total: 0, open: 0, resolved: 0}, 0);
        loadAnnotations();
        initSelectionCapture(content);

        // Close the open panel when the user presses Escape.
        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Escape' || e.key === 'Esc') && _openPanel) {
                closePanel();
            }
        });

        // Keep gutter markers aligned with their highlights when the viewport
        // width changes: both the .page column and the highlights reflow.
        window.addEventListener('resize', repositionMarkers);

        // Annotations now render at DOMContentLoaded (the list ships inline),
        // so late-loading images/web fonts can still shift the layout under the
        // already-placed markers. Re-align them once everything has loaded.
        window.addEventListener('load', repositionMarkers);
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
     * Load all annotations for the current page and render them.
     *
     * Fast path: action.php normally ships the list inline with the page (in
     * JSINFO.annotations.annotations), so we render straight away with no
     * round-trip. Only heavily-annotated pages omit the inline list, in which
     * case we fall back to the GET 'load' endpoint.
     */
    function loadAnnotations() {
        if (Array.isArray(_info.annotations)) {
            ingestAnnotations(_info.annotations);
            return;
        }

        // Fallback: the inline list was too large to embed. Fetch it instead.
        // action.php's AJAX handler accepts action=load as a GET query.
        fetch(AJAX_URL + '&action=load&id=' + encodeURIComponent(_info.pageId), {
            method: 'GET',
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !Array.isArray(data.annotations)) {
                return;
            }
            ingestAnnotations(data.annotations);
        }).catch(function () {
            // Graceful degradation: page still works without annotations.
        });
    }

    /**
     * Store a loaded annotation list (inline or fetched) and render everything.
     *
     * @param {Array} list  annotation objects from the server
     */
    function ingestAnnotations(list) {
        list.forEach(function (ann) {
            _annotations.set(ann.id, ann);
        });
        renderAll();
    }

    /**
     * Re-render everything: highlights, gutter markers, counter.
     */
    function renderAll() {
        clearHighlights();
        clearGutterMarkers();

        var content = document.getElementById(CONTENT_ID);
        if (!content) return;

        // Snapshot the page text ONCE, before any highlight is inserted.
        // Re-collecting per annotation would exclude already-wrapped text
        // (collectTextChunks skips our own UI), shifting every later anchor.
        var chunks  = collectTextChunks(content);
        var rawFull = chunks.map(function (c) { return c.text; }).join('');
        var nm      = normalizeWithMap(rawFull);

        // Phase 1 — locate every annotation against the clean snapshot.
        var hits = [];
        _annotations.forEach(function (ann) {
            ann._range       = null;
            ann._highlightEl = null;
            var hit = ann.anchor ? locate(nm.norm, ann.anchor) : null;
            if (hit) {
                hits.push({ann: ann, pos: hit.pos, len: hit.len});
                ann._orphaned = false;
            } else {
                ann._orphaned = true;
            }
        });

        // Phase 2 — wrap later matches first, so wrapping (which splits text
        // nodes) never invalidates the offsets of earlier, not-yet-wrapped ones.
        hits.sort(function (a, b) { return b.pos - a.pos; });
        hits.forEach(function (h) {
            var range = buildRange(chunks, nm.map, h.pos, h.len);
            if (range) {
                h.ann._range = range; // cache for panel positioning
                wrapHighlight(range, h.ann);
            } else {
                h.ann._orphaned = true;
            }
        });

        renderGutterMarkers();
        updateCounter(); // recounts orphans from the _orphaned flags set above
    }

    // -----------------------------------------------------------------------
    // Text anchoring (re-anchoring)
    // -----------------------------------------------------------------------

    /**
     * Locate an anchor's quoted text within the normalised page text.
     *
     * Algorithm:
     *   1. Search for the exact quote (normalised).
     *   2. If found multiple times, use prefix/suffix to disambiguate.
     *   3. If still ambiguous, use the start offset hint.
     *
     * Returns offsets into the normalised string; buildRange maps them back
     * to a DOM Range via the normalised→raw index map.
     *
     * @param {string} norm    normalised page text (from normalizeWithMap)
     * @param {object} anchor  {exact, prefix, suffix, start}
     * @returns {{pos:number, len:number}|null}
     */
    function locate(norm, anchor) {
        if (!anchor || !anchor.exact) return null;

        var exact = normalizeWS(anchor.exact);
        if (exact === '') return null;
        var prefix = normalizeWS(anchor.prefix || '');
        var suffix = normalizeWS(anchor.suffix || '');
        var hint   = anchor.start || 0;

        // Find all occurrences of exact.
        var positions = [];
        var from = 0;
        var idx;
        while ((idx = norm.indexOf(exact, from)) !== -1) {
            positions.push(idx);
            from = idx + exact.length;
        }

        if (positions.length === 0) return null;

        var chosen = positions[0];

        if (positions.length > 1) {
            // Disambiguate using prefix + suffix context, tie-break on the hint.
            var bestScore = -1;
            positions.forEach(function (pos) {
                var pre = norm.slice(Math.max(0, pos - prefix.length), pos);
                var suf = norm.slice(pos + exact.length, pos + exact.length + suffix.length);
                var score = 0;
                if (prefix && pre.indexOf(prefix) !== -1) score++;
                if (suffix && suf.indexOf(suffix) !== -1) score++;
                var distToHint = Math.abs(pos - hint);
                if (score > bestScore ||
                    (score === bestScore && distToHint < Math.abs(chosen - hint))) {
                    bestScore = score;
                    chosen    = pos;
                }
            });
        }

        return {pos: chosen, len: exact.length};
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
     * True if the given node is inside an existing highlight span.
     * Used to block opening a new-annotation tooltip on already-annotated text.
     *
     * @param {Node} node
     * @returns {bool}
     */
    function isInsideHighlight(node) {
        var el = (node && node.nodeType === 1) ? node : (node ? node.parentNode : null);
        while (el && el !== document.body) {
            if (el.className &&
                (el.className.indexOf(CLS_HIGHLIGHT_OPEN)     !== -1 ||
                 el.className.indexOf(CLS_HIGHLIGHT_RESOLVED) !== -1)) {
                return true;
            }
            el = el.parentNode;
        }
        return false;
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
     * Turn a (start, length) offset in the normalised page text back into a
     * DOM Range, using the normalised→raw index map.
     *
     * @param {Array<{node:Text, start:number, text:string}>} chunks
     * @param {Array<number>} map       normalised index → raw index (normalizeWithMap)
     * @param {number}        startOff  start offset in the normalised text
     * @param {number}        length    length in normalised characters
     * @returns {Range|null}
     */
    function buildRange(chunks, map, startOff, length) {
        var rawStart = map[startOff];
        var rawEnd   = map[startOff + length - 1];
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
     * Normalise raw text exactly as normalizeWS does (collapse each whitespace
     * run to a single space, trim both ends) while recording, for every
     * character of the normalised string, the index of the raw character it
     * came from. Returns {norm, map} with raw.charAt(map[i]) === norm.charAt(i)
     * (a collapsed internal space maps to the first char of its run).
     *
     * Normalisation and the index map MUST stay in lockstep: an earlier
     * version built the map without trimming, so a leading whitespace text
     * node (DokuWiki indents its content markup, so there always is one)
     * shifted every highlight one character to the left.
     *
     * @param {string} raw
     * @returns {{norm:string, map:Array<number>}}
     */
    function normalizeWithMap(raw) {
        var norm     = '';
        var map      = [];
        var inRun    = false;
        var runStart = 0;
        for (var i = 0; i < raw.length; i++) {
            if (/\s/.test(raw[i])) {
                if (!inRun) { inRun = true; runStart = i; }
                continue;
            }
            if (inRun) {
                inRun = false;
                // internal run → one representative space; leading run → dropped
                if (norm.length > 0) {
                    norm += ' ';
                    map.push(runStart);
                }
            }
            norm += raw[i];
            map.push(i);
        }
        // a trailing whitespace run is dropped (matches trim)
        return {norm: norm, map: map};
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
        var preview = ann.body || '';
        var span = document.createElement('span');
        span.className = ann.status === 'resolved'
            ? CLS_HIGHLIGHT_RESOLVED
            : CLS_HIGHLIGHT_OPEN;
        span.dataset.annId = ann.id;
        span.title = preview.slice(0, 80) + (preview.length > 80 ? '…' : '');
        span.addEventListener('click', function (e) {
            e.stopPropagation();
            openPanel(ann.id);
        });

        try {
            range.surroundContents(span);
            ann._highlightEl = span;
        } catch (e) {
            // surroundContents throws if the range crosses element boundaries;
            // fall back to extract + insert, reusing the same (still-empty) span.
            try {
                span.appendChild(range.extractContents());
                range.insertNode(span);
                ann._highlightEl = span;
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
            '.' + CLS_HIGHLIGHT_OPEN + ', .' + CLS_HIGHLIGHT_RESOLVED
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
     * Render a small marker for every anchored annotation. Markers are
     * appended to document.body as absolutely-positioned elements so that
     * template overflow rules on inner containers cannot clip them.
     *
     * All markers share the same X position — just to the left of the .page
     * content column — so they form a tidy vertical column in the margin.
     */
    function renderGutterMarkers() {
        var scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        var markerLeft = gutterMarkerLeft(scrollLeft);

        // Speech bubble SVG — clearly communicates "annotation here".
        var ICON_SVG =
            '<svg viewBox="0 0 16 16" fill="currentColor" width="10" height="10" aria-hidden="true">' +
            '<rect x="1" y="1" width="14" height="10" rx="2"/>' +
            '<path d="M4 14 L4 11 L8 11 Z"/>' +
            '</svg>';

        _annotations.forEach(function (ann) {
            if (!ann._highlightEl) return; // orphan

            var rect = ann._highlightEl.getBoundingClientRect();

            var marker = document.createElement('button');
            marker.className      = CLS_GUTTER_MARKER;
            marker.dataset.annId  = ann.id;
            marker.dataset.status = ann.status || 'open'; // drives CSS amber/green colour
            marker.setAttribute('aria-label', t('label_annotation', 'Annotation'));
            marker.type      = 'button';
            marker.innerHTML = ICON_SVG;
            // Align vertically with the first line of the highlight.
            marker.style.top  = (rect.top + scrollTop + 3) + 'px';
            marker.style.left = markerLeft + 'px';
            marker.addEventListener('click', function (e) {
                e.stopPropagation();
                openPanel(ann.id);
            });
            document.body.appendChild(marker);
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

    /**
     * The shared X position (document coordinates) for every gutter marker:
     * just inside the left padding of the .page content column, so the markers
     * form a tidy vertical strip in the margin. Falls back to 4px when the
     * column cannot be measured. Reads the theme's computed padding so it
     * adapts to the template.
     *
     * @param {number} scrollLeft current horizontal scroll offset
     * @returns {number}
     */
    function gutterMarkerLeft(scrollLeft) {
        var pageEl = document.querySelector('.' + PAGE_CLS) || document.getElementById(CONTENT_ID);
        if (!pageEl) return 4;
        var pageRect = pageEl.getBoundingClientRect();
        var padLeft  = parseInt(window.getComputedStyle(pageEl).paddingLeft, 10) || 32;
        return pageRect.left + scrollLeft + Math.max(2, Math.floor(padLeft * 0.25));
    }

    /**
     * Re-align every existing marker with its highlight without rebuilding the
     * DOM. Highlights shift when a panel is inserted/removed or the window is
     * resized, but markers live in document.body at absolute coordinates, so
     * they would otherwise drift out of line. Cheap — only touches inline
     * top/left on the handful of markers present.
     */
    function repositionMarkers() {
        var scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        var markerLeft = gutterMarkerLeft(scrollLeft);
        _annotations.forEach(function (ann) {
            if (!ann._markerEl || !ann._highlightEl) return;
            var rect = ann._highlightEl.getBoundingClientRect();
            ann._markerEl.style.top  = (rect.top + scrollTop + 3) + 'px';
            ann._markerEl.style.left = markerLeft + 'px';
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
            ? t('counter_annotation', '1 annotation')
            : fmt(t('counter_annotations', '%d annotations'), total);
        bar.appendChild(document.createTextNode(label));

        if (orphanCount > 0) {
            bar.appendChild(document.createTextNode(' · '));
            var orphanLink = document.createElement('a');
            orphanLink.href = '#ann-orphan-drawer';
            orphanLink.className = 'ann-orphan-link';
            orphanLink.textContent = fmt(t('counter_orphaned', '%d orphaned'), orphanCount);
            orphanLink.addEventListener('click', function (e) {
                e.preventDefault();
                toggleOrphanDrawer();
                repositionMarkers();
            });
            bar.appendChild(orphanLink);
        }

        if (_isAdmin && (stats.resolved > 0 || orphanCount > 0)) {
            if (stats.resolved > 0) {
                var btnCR = document.createElement('button');
                btnCR.type = 'button';
                btnCR.className = 'ann-btn ann-btn-admin';
                btnCR.textContent = t('btn_clear_resolved', 'Clear resolved');
                btnCR.addEventListener('click', function () { doClearResolved(btnCR); });
                bar.appendChild(btnCR);
            }
            if (orphanCount > 0) {
                var btnCO = document.createElement('button');
                btnCO.type = 'button';
                btnCO.className = 'ann-btn ann-btn-admin';
                btnCO.textContent = t('btn_clear_orphaned', 'Clear orphaned');
                btnCO.addEventListener('click', function () { doClearOrphaned(btnCO); });
                bar.appendChild(btnCO);
            }
        }

        // Insert inside .page, right after #dw__toc if present.
        // The TOC is float:right so placing the bar after it (not before) lets
        // it sit to the left of the float instead of pushing the TOC down.
        var pageEl = document.querySelector('.' + PAGE_CLS);
        if (pageEl) {
            var toc = pageEl.querySelector('#dw__toc');
            if (toc && toc.nextSibling) {
                pageEl.insertBefore(bar, toc.nextSibling);
            } else if (toc) {
                pageEl.appendChild(bar);
            } else {
                pageEl.insertBefore(bar, pageEl.firstChild);
            }
        } else {
            var content = document.getElementById(CONTENT_ID);
            if (content) content.insertBefore(bar, content.firstChild);
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
     * @param {string}  annId
     * @param {boolean} [focusReply]  focus the reply box once open (default true);
     *                                reopenPanel passes false so re-rendering after
     *                                an action doesn't yank the viewport to the form.
     */
    function openPanel(annId, focusReply) {
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

        if (focusReply !== false) {
            var input = panel.querySelector('.ann-body-input');
            if (input) input.focus();
        }

        // The panel grew the document; nudge markers below it back into line.
        repositionMarkers();
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
        repositionMarkers();
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
        panel.dataset.annId  = ann.id;
        panel.dataset.status = ann.status || 'open'; // drives the resolved accent in style.css

        // Main annotation thread entry (close button lives in its meta row).
        var rootEntry = buildThreadEntry(ann, true);
        var meta = rootEntry.querySelector('.ann-meta');
        if (meta) {
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'ann-btn ann-close';
            closeBtn.setAttribute('aria-label', t('label_close', 'Close'));
            closeBtn.textContent = '×'; // ×
            closeBtn.style.marginLeft = 'auto';
            closeBtn.addEventListener('click', closePanel);
            meta.appendChild(closeBtn);
        }
        panel.appendChild(rootEntry);

        // Replies: build hierarchy from flat list and render depth-indented.
        appendReplyTree(panel, ann, buildReplyTree(ann.replies || []), 0);

        // Reply form at the bottom for root-level replies.
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
            resolveBtn.className = 'ann-btn ann-btn-primary';
            resolveBtn.textContent = ann.status === 'resolved'
                ? t('btn_reopen', 'Reopen')
                : t('btn_resolve', 'Resolve');
            resolveBtn.addEventListener('click', function () {
                doResolve(ann.id, ann.status === 'resolved' ? 'open' : 'resolved', resolveBtn);
            });
            actions.appendChild(resolveBtn);
        }

        // Edit + Delete (own or admin)
        var canEdit = _isAdmin || ann.author === currentUser();
        if (canEdit && _loggedIn) {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'ann-btn';
            editBtn.textContent = t('btn_edit', 'Edit');
            editBtn.addEventListener('click', function () {
                showEditForm(entry, ann, 'annotation');
            });
            actions.appendChild(editBtn);

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'ann-btn ann-btn-danger';
            delBtn.textContent = t('btn_delete', 'Delete');
            delBtn.addEventListener('click', function () {
                if (confirm(t('confirm_delete', 'Delete this annotation?'))) {
                    doDeleteAnnotation(ann.id, delBtn);
                }
            });
            actions.appendChild(delBtn);
        }

        entry.appendChild(actions);
        return entry;
    }

    /**
     * Build the DOM for one reply entry, indented according to its nesting depth.
     *
     * @param {object} ann    parent annotation
     * @param {object} reply
     * @param {number} depth  0 = direct reply to annotation; 1+ = nested
     * @returns {HTMLElement}
     */
    function buildReplyEntry(ann, reply, depth) {
        var entry = document.createElement('div');
        entry.className = 'ann-thread-entry ann-reply';
        entry.dataset.replyId = reply.id;
        // Indent nested replies up to 4 levels (1.5 em each).
        var indent = Math.min(depth, 4) * 1.5 + 1.5;
        if (indent > 0) {
            entry.style.marginLeft = indent + 'em';
        }

        entry.appendChild(buildMeta(reply.author, reply.created, null));

        var bodyEl = document.createElement('div');
        bodyEl.className = 'ann-body';
        bodyEl.textContent = reply.body;
        entry.appendChild(bodyEl);

        var actions = document.createElement('div');
        actions.className = 'ann-actions';

        // "Reply to this reply" button for logged-in users.
        if (_loggedIn) {
            var replyToBtn = document.createElement('button');
            replyToBtn.type = 'button';
            replyToBtn.className = 'ann-btn ann-btn-primary';
            replyToBtn.textContent = t('btn_reply', 'Reply');
            replyToBtn.addEventListener('click', function () {
                // Toggle an inline reply form directly after this entry.
                var next = entry.nextSibling;
                if (next && next.classList && next.classList.contains('ann-inline-reply')) {
                    next.parentNode.removeChild(next);
                    return;
                }
                var form = buildInlineReplyForm(ann, reply.id, depth + 1);
                entry.parentNode.insertBefore(form, entry.nextSibling);
                var ta = form.querySelector('.ann-body-input');
                if (ta) ta.focus();
            });
            actions.appendChild(replyToBtn);
        }

        var canEdit = _isAdmin || reply.author === currentUser();
        if (canEdit && _loggedIn) {
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'ann-btn';
            editBtn.textContent = t('btn_edit', 'Edit');
            editBtn.addEventListener('click', function () {
                showEditForm(entry, {annId: ann.id, replyId: reply.id, body: reply.body}, 'reply');
            });
            actions.appendChild(editBtn);

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'ann-btn ann-btn-danger';
            delBtn.textContent = t('btn_delete', 'Delete');
            delBtn.addEventListener('click', function () {
                if (confirm(t('confirm_delete_reply', 'Delete this reply?'))) {
                    doDeleteReply(ann.id, reply.id, delBtn);
                }
            });
            actions.appendChild(delBtn);
        }

        entry.appendChild(actions);
        return entry;
    }

    /**
     * Build a nested tree structure from a flat reply list. Replies without a
     * known parentId (including legacy replies with no parentId field) are
     * treated as root-level.
     *
     * @param {Array} replies  flat array of reply objects
     * @returns {Array}        array of {reply, children} nodes
     */
    function buildReplyTree(replies) {
        var map = {};
        var roots = [];
        replies.forEach(function (r) {
            map[r.id] = {reply: r, children: []};
        });
        replies.forEach(function (r) {
            var pid = r.parentId || '';
            if (pid && map[pid]) {
                map[pid].children.push(map[r.id]);
            } else {
                roots.push(map[r.id]);
            }
        });
        return roots;
    }

    /**
     * Recursively append reply entries into the panel.
     *
     * @param {HTMLElement} panel
     * @param {object}      ann
     * @param {Array}       nodes  array of {reply, children} tree nodes
     * @param {number}      depth
     */
    function appendReplyTree(panel, ann, nodes, depth) {
        nodes.forEach(function (node) {
            panel.appendChild(buildReplyEntry(ann, node.reply, depth));
            if (node.children.length > 0) {
                appendReplyTree(panel, ann, node.children, depth + 1);
            }
        });
    }

    /**
     * Build an inline reply form that appears directly below a reply entry.
     *
     * @param {object} ann           parent annotation
     * @param {string} parentReplyId id of the reply being replied to
     * @param {number} depth         visual nesting depth for the new reply
     * @returns {HTMLElement}
     */
    function buildInlineReplyForm(ann, parentReplyId, depth) {
        var form = document.createElement('div');
        form.className = 'ann-thread-entry ann-reply ann-inline-reply';
        var indent = Math.min(depth, 4) * 1.5 + 1.5;
        if (indent > 0) {
            form.style.marginLeft = indent + 'em';
        }

        var ta = document.createElement('textarea');
        ta.className = 'ann-body-input';
        ta.placeholder = t('placeholder_reply', 'Write a reply…');
        ta.rows = 3;
        form.appendChild(ta);

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'ann-btn ann-btn-primary';
        submitBtn.textContent = t('btn_reply', 'Reply');
        submitBtn.addEventListener('click', function () {
            var body = ta.value.trim();
            if (!body) return;
            doAddReply(ann.id, body, function () {
                if (form.parentNode) form.parentNode.removeChild(form);
            }, submitBtn, parentReplyId);
        });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'ann-btn';
        cancelBtn.textContent = t('btn_cancel', 'Cancel');
        cancelBtn.addEventListener('click', function () {
            if (form.parentNode) form.parentNode.removeChild(form);
        });

        row.appendChild(submitBtn);
        row.appendChild(cancelBtn);
        form.appendChild(row);
        return form;
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
        authorEl.textContent = author || t('label_unknown', 'Unknown');
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
            pill.textContent = status === 'resolved'
                ? t('status_resolved', 'Resolved')
                : t('status_open', 'Open');
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
        ta.placeholder = t('placeholder_reply', 'Write a reply…');
        ta.rows = 3;
        form.appendChild(ta);

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'ann-btn ann-btn-primary';
        submitBtn.textContent = t('btn_reply', 'Reply');
        submitBtn.addEventListener('click', function () {
            var body = ta.value.trim();
            if (!body) return;
            doAddReply(ann.id, body, function () {
                ta.value = '';
            }, submitBtn);
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
        ta.rows = 3;

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'ann-btn ann-btn-primary';
        saveBtn.textContent = t('btn_save', 'Save');
        saveBtn.addEventListener('click', function () {
            var newBody = ta.value.trim();
            if (!newBody) return;
            if (type === 'annotation') {
                doEditAnnotation(data.id || _openAnnId, newBody, saveBtn);
            } else {
                doEditReply(data.annId, data.replyId, newBody, saveBtn);
            }
        });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'ann-btn';
        cancelBtn.textContent = t('btn_cancel', 'Cancel');
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
     * Keep the orphan drawer in step with the current orphan set after a
     * mutation (delete / clear). No-op when the drawer is closed. When it is
     * open, rebuild it from the live _orphaned flags so deleted entries
     * disappear; if no orphans remain, remove the drawer entirely instead of
     * leaving an empty shell behind.
     *
     * Must run after renderAll(), which recomputes every ann._orphaned flag.
     */
    function syncOrphanDrawer() {
        var drawer = document.getElementById('ann-orphan-drawer');
        if (!drawer) return; // drawer not open — nothing to do

        var hasOrphans = false;
        _annotations.forEach(function (ann) {
            if (ann._orphaned) hasOrphans = true;
        });

        if (drawer.parentNode) drawer.parentNode.removeChild(drawer);
        if (hasOrphans) {
            renderOrphanDrawer();
            repositionMarkers();
        }
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
        heading.textContent = t('orphaned_heading', 'Orphaned annotations');
        drawer.appendChild(heading);

        var note = document.createElement('p');
        note.className = 'ann-orphan-note';
        note.textContent = t('orphaned_note',
            'These annotations reference text that no longer appears on the page.');
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
            empty.textContent = t('orphaned_none', 'None.');
            drawer.appendChild(empty);
        }

        // Insert right below the counter bar, which lives inside .page.
        // All fallbacks also target .page so the drawer never stretches past
        // the content column.
        var bar = document.getElementById('ann-counter-bar');
        if (bar && bar.parentNode) {
            bar.parentNode.insertBefore(drawer, bar.nextSibling);
        } else {
            var pageEl2 = document.querySelector('.' + PAGE_CLS);
            if (pageEl2) {
                pageEl2.insertBefore(drawer, pageEl2.firstChild);
            } else {
                content.insertBefore(drawer, content.firstChild);
            }
        }
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

        // Close tooltip on click outside (but not when clicking the new-form).
        document.addEventListener('mousedown', function (e) {
            var tooltip = document.getElementById('ann-tooltip');
            if (tooltip && !tooltip.contains(e.target)) {
                var naf = document.getElementById('ann-new-form');
                if (!naf || !naf.contains(e.target)) {
                    hideTooltip();
                }
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
            // Don't hide the tooltip if the mouseup came from inside it —
            // the click handler is responsible for cleanup in that case.
            var tip = document.getElementById('ann-tooltip');
            if (tip && tip.contains(e.target)) {
                return;
            }
            // Don't hide if a new-annotation form is open (user clicked
            // inside the form, collapsing the original selection).
            var naf = document.getElementById('ann-new-form');
            if (naf && naf.contains(e.target)) {
                return;
            }
            hideTooltip();
            return;
        }
        var range = sel.getRangeAt(0);
        if (!content.contains(range.commonAncestorContainer)) {
            hideTooltip();
            return;
        }
        // Don't open a new annotation when the selection overlaps existing annotated text.
        if (isInsideHighlight(range.startContainer) || isInsideHighlight(range.endContainer)) {
            hideTooltip();
            return;
        }
        var text = sel.toString().trim();
        if (text.length < 1) {
            hideTooltip();
            return;
        }

        // If the tooltip is already showing (e.g. user moused up after
        // pressing the Annotate button), don't replace it with a fresh one —
        // that would orphan the button mid-click and break the click handler.
        if (document.getElementById('ann-tooltip')) {
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

        // Capture the anchor on mousedown while the selection is guaranteed
        // to still exist. By the time 'click' fires, many browsers have
        // already collapsed the selection, so captureAnchor would return null.
        // _pendingAnchor is module-level so it survives tooltip replacement.
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = t('btn_annotate', 'Annotate');
        btn.className = 'ann-btn ann-btn-primary';
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault(); // prevent focus-change deselection
            // Capture now, while the selection is still intact.
            _pendingAnchor = captureAnchor(sel, range, content);
        });
        btn.addEventListener('click', function () {
            var anchor = _pendingAnchor;
            _pendingAnchor = null;
            hideTooltip();
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
        // Note: ann-new-form is NOT removed here — it has its own Cancel
        // button and must survive the mouseup that fires after the click.
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
        var nm       = normalizeWithMap(fullRaw);
        var fullNorm = nm.norm;

        // Find where this text node + offset lands in the raw full text.
        var rawStart = 0;
        for (var i = 0; i < chunks.length; i++) {
            var c = chunks[i];
            if (c.node === range.startContainer) {
                rawStart = c.start + range.startOffset;
                break;
            }
        }

        // Map that raw offset to an offset in the normalised text, using the
        // same map as re-anchoring so capture and find stay in agreement.
        var normStart = nm.norm.length;
        for (var j = 0; j < nm.map.length; j++) {
            if (nm.map[j] >= rawStart) {
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
        ta.placeholder = t('placeholder_body', 'Add a comment…');
        ta.rows = 3;
        form.appendChild(ta);

        var row = document.createElement('div');
        row.className = 'ann-form-row';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'ann-btn ann-btn-primary';
        submitBtn.textContent = t('btn_annotate', 'Annotate');
        submitBtn.addEventListener('click', function () {
            var body = ta.value.trim();
            if (!body) return;
            doCreate(anchor, body, function () {
                if (form.parentNode) form.parentNode.removeChild(form);
            }, submitBtn);
        });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'ann-btn';
        cancelBtn.textContent = t('btn_cancel', 'Cancel');
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
     * @param {object}        anchor
     * @param {string}        body
     * @param {Function}      onSuccess
     * @param {HTMLElement}   [btn]  button to disable while the request is in flight
     */
    function doCreate(anchor, body, onSuccess, btn) {
        setBusy(btn, true);
        ajax({
            action: 'create',
            id:     _info.pageId,
            anchor: anchor,
            body:   body,
        }).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t('error_save', 'Could not save — please try again.'), data);
                return;
            }
            var ann = data.annotation;
            _annotations.set(ann.id, ann);
            if (typeof onSuccess === 'function') onSuccess(ann);
            renderAll();
        }).catch(function () {
            setBusy(btn, false);
            alert(t('error_save', 'Could not save — please try again.'));
        });
    }

    /**
     * Run a thread-level mutation (reply / edit annotation / edit reply /
     * delete reply): POST the payload, then on success store the returned
     * annotation — keeping the client-side render state via mergeClientProps —
     * and re-open its panel. The server returns the full updated annotation, so
     * no second GET is needed. These four actions share this exact shape;
     * create / delete-annotation / resolve differ (they re-render the whole
     * overlay) and stay separate below.
     *
     * @param {object}      payload  AJAX body; must carry annId
     * @param {HTMLElement} [btn]    button to show the busy spinner on
     * @param {string}      errKey   lang key for the failure message
     * @param {string}      errText  English fallback for that message
     * @param {Function}    [onOk]   optional callback run before re-rendering
     */
    function submitThreadAction(payload, btn, errKey, errText, onOk) {
        setBusy(btn, true);
        ajax(payload).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t(errKey, errText), data);
                return;
            }
            _annotations.set(data.annotation.id, mergeClientProps(data.annotation));
            if (typeof onOk === 'function') onOk();
            reopenPanel(payload.annId);
        }).catch(function () {
            setBusy(btn, false);
            alert(t(errKey, errText));
        });
    }

    /**
     * POST reply action and refresh the open panel.
     *
     * @param {string}      annId
     * @param {string}      body
     * @param {Function}    onSuccess
     * @param {HTMLElement} [btn]
     * @param {string}      [parentReplyId]  id of the reply being replied to, or ''
     */
    function doAddReply(annId, body, onSuccess, btn, parentReplyId) {
        submitThreadAction({
            action:   'reply',
            id:       _info.pageId,
            annId:    annId,
            body:     body,
            parentId: parentReplyId || '',
        }, btn, 'error_save', 'Could not save — please try again.', onSuccess);
    }

    /**
     * POST edit_annotation and re-render.
     *
     * @param {string}      annId
     * @param {string}      body
     * @param {HTMLElement} [btn]
     */
    function doEditAnnotation(annId, body, btn) {
        submitThreadAction({
            action: 'edit_annotation',
            id:     _info.pageId,
            annId:  annId,
            body:   body,
        }, btn, 'error_save', 'Could not save — please try again.');
    }

    /**
     * POST edit_reply and re-render.
     *
     * @param {string}      annId
     * @param {string}      replyId
     * @param {string}      body
     * @param {HTMLElement} [btn]
     */
    function doEditReply(annId, replyId, body, btn) {
        submitThreadAction({
            action:  'edit_reply',
            id:      _info.pageId,
            annId:   annId,
            replyId: replyId,
            body:    body,
        }, btn, 'error_save', 'Could not save — please try again.');
    }

    /**
     * POST delete_annotation.
     *
     * @param {string}      annId
     * @param {HTMLElement} [btn]
     */
    function doDeleteAnnotation(annId, btn) {
        setBusy(btn, true);
        ajax({
            action: 'delete_annotation',
            id:     _info.pageId,
            annId:  annId,
        }).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t('error_delete', 'Could not delete — please try again.'), data);
                return;
            }
            _annotations.delete(annId);
            closePanel();
            renderAll();
            // If this was deleted from the open orphan drawer, refresh it —
            // and remove it entirely once the last orphan is gone.
            syncOrphanDrawer();
        }).catch(function () {
            setBusy(btn, false);
        });
    }

    /**
     * POST delete_reply and re-render.
     *
     * @param {string}      annId
     * @param {string}      replyId
     * @param {HTMLElement} [btn]
     */
    function doDeleteReply(annId, replyId, btn) {
        submitThreadAction({
            action:  'delete_reply',
            id:      _info.pageId,
            annId:   annId,
            replyId: replyId,
        }, btn, 'error_delete', 'Could not delete — please try again.');
    }

    /**
     * POST resolve/reopen action.
     *
     * @param {string}      annId
     * @param {string}      status  'open' | 'resolved'
     * @param {HTMLElement} [btn]
     */
    function doResolve(annId, status, btn) {
        setBusy(btn, true);
        ajax({
            action: 'resolve',
            id:     _info.pageId,
            annId:  annId,
            status: status,
        }).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t('error_status', 'Could not update the status — please try again.'), data);
                return;
            }
            _annotations.set(data.annotation.id, data.annotation);
            renderAll();
            reopenPanel(annId);
        }).catch(function () {
            setBusy(btn, false);
        });
    }

    /**
     * POST clear_resolved (admin).
     *
     * @param {HTMLElement} [btn]  button to show the busy spinner on
     */
    function doClearResolved(btn) {
        if (!confirm(t('confirm_clear_resolved', 'Delete all resolved annotations on this page?'))) return;
        setBusy(btn, true);
        ajax({
            action: 'clear_resolved',
            id:     _info.pageId,
        }).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t('error_clear', 'Could not clear — please try again.'), data);
                return;
            }
            // Remove resolved from local state.
            _annotations.forEach(function (ann, id) {
                if (ann.status === 'resolved') _annotations.delete(id);
            });
            closePanel();
            renderAll();
            // Deleting resolved orphans may empty the drawer — sync/remove it.
            syncOrphanDrawer();
        }).catch(function () {
            setBusy(btn, false);
            alert(t('error_clear', 'Could not clear — please try again.'));
        });
    }

    /**
     * POST clear_orphaned (admin).
     *
     * @param {HTMLElement} [btn]  button to show the busy spinner on
     */
    function doClearOrphaned(btn) {
        if (!confirm(t('confirm_clear_orphaned', 'Delete all orphaned annotations on this page?'))) return;
        setBusy(btn, true);
        ajax({
            action: 'clear_orphaned',
            id:     _info.pageId,
        }).then(function (data) {
            setBusy(btn, false);
            if (!data.success) {
                showError(t('error_clear', 'Could not clear — please try again.'), data);
                return;
            }
            _annotations.forEach(function (ann, id) {
                if (ann._orphaned) _annotations.delete(id);
            });
            closePanel();
            renderAll();
            // All orphans are gone now — tear down the drawer if it is open.
            syncOrphanDrawer();
        }).catch(function () {
            setBusy(btn, false);
            alert(t('error_clear', 'Could not clear — please try again.'));
        });
    }

    // -----------------------------------------------------------------------
    // Panel management helpers
    // -----------------------------------------------------------------------

    /**
     * Close the current panel and re-open it (preserves scroll position and
     * re-renders the thread with fresh data).
     *
     * @param {string} annId
     */
    function reopenPanel(annId) {
        // closePanel() first clears _openAnnId so openPanel() rebuilds instead
        // of treating the same id as a toggle. focusReply=false keeps the
        // viewport put after resolve / edit / delete actions.
        closePanel();
        openPanel(annId, false);
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Disable a button and show a spinner while an AJAX request is in flight;
     * restore label and width on completion.
     *
     * @param {HTMLElement|null|undefined} btn
     * @param {boolean}                   busy
     */
    function setBusy(btn, busy) {
        if (!btn) return;
        if (busy) {
            btn.disabled = true;
            btn.dataset.prevText = btn.textContent;
            // Lock the width before clearing text so the button doesn't shrink.
            btn.style.minWidth = btn.offsetWidth + 'px';
            btn.textContent = ' '; // non-breaking space keeps height
            btn.classList.add('ann-btn-busy');
        } else {
            btn.disabled = false;
            btn.classList.remove('ann-btn-busy');
            if (btn.dataset.prevText !== undefined) {
                btn.textContent = btn.dataset.prevText;
                delete btn.dataset.prevText;
            }
            btn.style.minWidth = '';
        }
    }

    /**
     * Copy client-only runtime properties (_highlightEl, _markerEl,
     * _orphaned, _range) from the currently stored annotation onto a
     * freshly-returned server object before storing it, so that panels
     * reopen at the correct position instead of falling back to the
     * bottom of the page.
     *
     * @param {object} fresh  annotation object from the server
     * @returns {object}      the same object, augmented
     */
    function mergeClientProps(fresh) {
        var existing = _annotations.get(fresh.id);
        if (existing) {
            fresh._highlightEl = existing._highlightEl;
            fresh._markerEl    = existing._markerEl;
            fresh._orphaned    = existing._orphaned;
            fresh._range       = existing._range;
        }
        return fresh;
    }

    /**
     * The per-plugin JS language bundle, exposed by DokuWiki as
     * LANG.plugins.annotations (built from lang/<iso>/lang.php $lang['js']).
     *
     * @returns {object}
     */
    function uiLang() {
        if (typeof LANG !== 'undefined' && LANG && LANG.plugins && LANG.plugins.annotations) {
            return LANG.plugins.annotations;
        }
        return {};
    }

    /**
     * Look up a UI string by key, falling back to the supplied English text if
     * the bundle is missing the key (e.g. a lang file not yet updated).
     *
     * @param {string} key
     * @param {string} fallback  English default
     * @returns {string}
     */
    function t(key, fallback) {
        var s = _lang[key];
        return (s === undefined || s === null || s === '') ? fallback : s;
    }

    /**
     * Substitute a single %d placeholder with a number.
     *
     * @param {string} str
     * @param {number} n
     * @returns {string}
     */
    function fmt(str, n) {
        return String(str).replace('%d', n);
    }

    /**
     * Show a localised error, appending the server's reason in parentheses
     * when one is present.
     *
     * @param {string} base  localised message
     * @param {object} data  AJAX response ({error?:string})
     */
    function showError(base, data) {
        var reason = (data && data.error) ? data.error : '';
        alert(reason ? base + ' (' + reason + ')' : base);
    }

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
        return (jsinfo.annotations && jsinfo.annotations.user) ? jsinfo.annotations.user : '';
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
        if (diff < 60)        return t('time_now', 'just now');
        if (diff < 3600)      return fmt(t('time_minutes', '%dm ago'), Math.floor(diff / 60));
        if (diff < 86400)     return fmt(t('time_hours',   '%dh ago'), Math.floor(diff / 3600));
        if (diff < 86400 * 7) return fmt(t('time_days',    '%dd ago'), Math.floor(diff / 86400));
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
