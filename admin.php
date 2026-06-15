<?php

/**
 * Annotations plugin — admin overview.
 *
 * Lists every page that has stored annotations, with its normal (present),
 * resolved and orphaned counts, and lets an admin clear resolved or orphaned
 * annotations for one page or for every annotated page at once.
 *
 * The table reuses the JS-free pattern shared by the lastseen and usersettings
 * admin panels: sortable column headers, a per-column text filter (only the
 * Page column is filterable here — the count columns and the actions column are
 * not), and a numbered pager. The clear operations are POST forms guarded by a
 * CSRF token, with Post/Redirect/Get back to the overview.
 *
 * Permission: admin only (forAdminOnly), matching the helper's canClear() rule.
 * The counts are computed by helper::pageCounts(), which renders each page to
 * detect orphans (cached by p_wiki_xhtml); see DESIGN.md for the cost note.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Utf8\PhpString;

class admin_plugin_annotations extends AdminPlugin
{
    /** @var string[] columns that may be sorted */
    protected $sortable = ['page', 'normal', 'resolved', 'orphaned'];

    /**
     * Admin only — clearing annotations is an admin operation (helper::canClear).
     *
     * @return bool
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * Position in the admin menu.
     *
     * @return int
     */
    public function getMenuSort()
    {
        return 1000;
    }

    /**
     * Admin-menu label.
     *
     * @param string $language
     * @return string
     */
    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    // ---------------------------------------------------------------------
    //  Request handling (clear actions)
    // ---------------------------------------------------------------------

    /**
     * Process a submitted clear action, then Post/Redirect/Get back to the
     * overview so a reload does not repeat it. DokuWiki's admin dispatcher
     * enforces forAdminOnly() before this runs; the CSRF token and the helper's
     * canClear() rule are still checked.
     *
     * @return void
     */
    public function handle()
    {
        global $INPUT, $ID;

        $action = $INPUT->post->str('annotations_action');
        if ($action === '') {
            return;
        }
        if (!checkSecurityToken()) {
            return;
        }

        /** @var helper_plugin_annotations $helper */
        $helper = $this->loadHelper('annotations', false);
        if (!$helper || !$helper->canClear(auth_isadmin())) {
            return;
        }

        if ($action === 'clear_orphaned') {
            $page = cleanID($INPUT->post->str('clearpage'));
            if ($page !== '') {
                $count = $helper->clearOrphaned($page);
                if ($count === false) {
                    msg($this->getLang('clear_fail'), -1);
                } else {
                    msg(sprintf($this->getLang('cleared_page'), $count, hsc($page)), 1);
                }
            }
        } elseif ($action === 'clear_orphaned_all') {
            $count = $helper->clearOrphanedAll();
            msg(sprintf($this->getLang('cleared_all'), $count), 1);
        } elseif ($action === 'clear_resolved') {
            $page = cleanID($INPUT->post->str('clearpage'));
            if ($page !== '') {
                $count = $helper->clearResolved($page);
                if ($count === false) {
                    msg($this->getLang('clear_resolved_fail'), -1);
                } else {
                    msg(sprintf($this->getLang('cleared_resolved_page'), $count, hsc($page)), 1);
                }
            }
        } elseif ($action === 'clear_resolved_all') {
            $count = $helper->clearResolvedAll();
            msg(sprintf($this->getLang('cleared_resolved_all'), $count), 1);
        }

        send_redirect(wl($ID, $this->standingParams(), true, '&'));
    }

    // ---------------------------------------------------------------------
    //  Output
    // ---------------------------------------------------------------------

    /**
     * Render the overview: a wiki-wide "clear all orphaned" button, then a
     * sortable/filterable/paginated table of annotated pages.
     *
     * @return void
     */
    public function html()
    {
        global $INPUT, $ID;

        echo '<div class="plugin_annotations_admin">';
        echo '<h1>' . hsc($this->getLang('heading')) . '</h1>';

        /** @var helper_plugin_annotations $helper */
        $helper = $this->loadHelper('annotations', false);
        if (!$helper) {
            echo '<div class="error">' . hsc($this->getLang('helper_missing')) . '</div></div>';
            return;
        }

        // Build every row up front: the counts feed sorting, filtering and the
        // wiki-wide orphan total, so all annotated pages are processed here.
        $rows = $this->buildRows($helper);
        if ($rows === []) {
            echo '<p class="annotations_admin_none">' . hsc($this->getLang('none')) . '</p>';
            echo '</div>';
            return;
        }

        $totalOrphaned = 0;
        $totalResolved = 0;
        foreach ($rows as $r) {
            $totalOrphaned += $r['orphaned'];
            $totalResolved += $r['resolved'];
        }

        // request parameters
        $sort = $INPUT->str('sort', 'page');
        if (!in_array($sort, $this->sortable, true)) {
            $sort = 'page';
        }
        $dir     = ($INPUT->str('dir') === 'desc') ? 'desc' : 'asc';
        $filters = $this->activeFilters();

        $shown = $this->applyFilters($rows, $filters);
        $shown = $this->sortRows($shown, $sort, $dir);
        $total = count($shown);

        [$pageRows, $page, $totalPages, $from, $to] = $this->paginate($shown, (int) $this->getConf('entries_per_page'));

        echo '<p>' . hsc($this->getLang('intro')) . '</p>';

        // wiki-wide clear-all (counts all annotated pages, not just the filtered
        // view); each button targets a POST form rendered at the end via form=
        echo '<div class="annotations_admin_bar">';
        if ($totalResolved > 0) {
            echo '<button type="submit" form="ann_clear_resolved_all" class="button"'
               . $this->confirmAttr('confirm_clear_resolved_all') . '>'
               . hsc(sprintf($this->getLang('btn_clear_resolved_all'), $totalResolved)) . '</button>';
        }
        if ($totalOrphaned > 0) {
            echo '<button type="submit" form="ann_clear_orphaned_all" class="button"'
               . $this->confirmAttr('confirm_clear_all') . '>'
               . hsc(sprintf($this->getLang('btn_clear_all'), $totalOrphaned)) . '</button>';
        }
        echo '</div>';

        $cols   = ['page', 'normal', 'resolved', 'orphaned', 'actions'];
        $labels = [
            'page'     => $this->getLang('th_page'),
            'normal'   => $this->getLang('th_normal'),
            'resolved' => $this->getLang('th_resolved'),
            'orphaned' => $this->getLang('th_orphaned'),
            'actions'  => $this->getLang('th_actions'),
        ];

        // GET form so the filter combines with the sort links and bookmarks
        // cleanly; the action URL's query string is dropped on submit, so the
        // standing parameters travel as hidden fields.
        echo '<form class="annotations_admin_filter" method="get" action="' . DOKU_BASE . DOKU_SCRIPT . '">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '" />';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="annotations" />';
        echo '<input type="hidden" name="sort" value="' . hsc($sort) . '" />';
        echo '<input type="hidden" name="dir" value="' . hsc($dir) . '" />';
        echo '<input type="hidden" name="pg" value="1" />'; // a new search lands on page 1

        echo '<div class="table">';
        echo '<table class="inline plugin_annotations_admin">';
        echo '<thead><tr>';
        foreach ($cols as $c) {
            echo $this->headerCell($c, $labels[$c], $sort, $dir);
        }
        echo '</tr>';
        echo $this->renderFilterRow($cols, $filters, $sort, $dir);
        echo '</thead><tbody>';

        if ($total === 0) {
            echo '<tr><td colspan="' . count($cols) . '" class="annotations_admin_none">'
               . hsc($this->getLang('none_match')) . '</td></tr>';
        } else {
            foreach ($pageRows as $row) {
                echo $this->renderRow($row);
            }
        }

        echo '</tbody></table></div>';
        echo '</form>';

        echo $this->renderPager($page, $totalPages);

        if ($total > 0) {
            echo '<p class="annotations_admin_count">'
               . hsc(sprintf($this->getLang('shown'), $from, $to, $total)) . '</p>';
        }

        // POST forms targeted by the clear buttons (siblings of the GET form, so
        // no illegal nested <form>; buttons reach them via the HTML5 form= attr)
        echo $this->clearForms();

        echo '</div>';
    }

    /**
     * One table row per annotated page: counts plus per-page "clear resolved"
     * and "clear orphaned" submit buttons, each shown only when that page has
     * something of its kind to clear.
     *
     * @param array $row ['id','title','normal','resolved','orphaned']
     * @return string
     */
    protected function renderRow(array $row)
    {
        $html = '<tr>';

        // Page: title as a link to the page; the id as muted secondary text
        // (omitted when the title is just the id, i.e. the page has no heading).
        $html .= '<td class="annotations_admin_page">';
        $html .= '<a href="' . wl($row['id']) . '">' . hsc($row['title']) . '</a>';
        if ($row['title'] !== $row['id']) {
            $html .= ' <span class="annotations_admin_id">' . hsc($row['id']) . '</span>';
        }
        $html .= '</td>';

        $html .= '<td class="annotations_admin_num">' . ((int) $row['normal']) . '</td>';
        $html .= '<td class="annotations_admin_num">' . ((int) $row['resolved']) . '</td>';
        $html .= '<td class="annotations_admin_num">' . ((int) $row['orphaned']) . '</td>';

        $html .= '<td class="annotations_admin_actions">';
        if ($row['resolved'] > 0) {
            $html .= '<button type="submit" form="ann_clear_resolved_single" class="button"'
                  . ' name="clearpage" value="' . hsc($row['id']) . '"'
                  . $this->confirmAttr('confirm_clear_resolved_page') . '>'
                  . hsc($this->getLang('btn_clear_resolved')) . '</button>';
        }
        if ($row['orphaned'] > 0) {
            if ($row['resolved'] > 0) {
                $html .= ' ';
            }
            $html .= '<button type="submit" form="ann_clear_orphaned_single" class="button"'
                  . ' name="clearpage" value="' . hsc($row['id']) . '"'
                  . $this->confirmAttr('confirm_clear_page') . '>'
                  . hsc($this->getLang('btn_clear_orphaned')) . '</button>';
        }
        $html .= '</td>';

        return $html . '</tr>';
    }

    /**
     * Build one row per annotated page. Pages whose annotation file is present
     * but empty are skipped (helper::getAnnotatedPages already filters those,
     * but the count is re-checked here too).
     *
     * @param helper_plugin_annotations $helper
     * @return array list of ['id','title','normal','resolved','orphaned']
     */
    protected function buildRows($helper)
    {
        $rows = [];
        foreach ($helper->getAnnotatedPages() as $id) {
            $counts = $helper->pageCounts($id);
            if ($counts['total'] === 0) {
                continue;
            }
            $title = p_get_first_heading($id);
            if (!is_string($title) || $title === '') {
                $title = $id;
            }
            $rows[] = [
                'id'       => $id,
                'title'    => $title,
                'normal'   => $counts['normal'],
                'resolved' => $counts['resolved'],
                'orphaned' => $counts['orphaned'],
            ];
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    //  Filtering & sorting
    // ---------------------------------------------------------------------

    /**
     * The active page filter, read from the q[] array (only the Page column is
     * filterable). Returns [] or ['page' => term].
     *
     * @return array
     */
    protected function activeFilters()
    {
        global $INPUT;
        $raw = $INPUT->arr('q');
        if (isset($raw['page']) && is_string($raw['page'])) {
            $term = trim($raw['page']);
            if ($term !== '') {
                return ['page' => $term];
            }
        }
        return [];
    }

    /**
     * Keep only rows whose title OR id matches the page filter (substring,
     * case-insensitive).
     *
     * @param array $rows
     * @param array $filters
     * @return array
     */
    protected function applyFilters(array $rows, array $filters)
    {
        if (!isset($filters['page'])) {
            return $rows;
        }
        $term = $filters['page'];
        return array_values(array_filter($rows, function ($row) use ($term) {
            return $this->matches($row['title'], $term) || $this->matches($row['id'], $term);
        }));
    }

    /**
     * Case-insensitive UTF-8 substring test.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    protected function matches($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        $h = PhpString::strtolower((string) $haystack);
        $n = PhpString::strtolower((string) $needle);
        return PhpString::strpos($h, $n) !== false;
    }

    /**
     * Sort rows by the given column and direction. The page column sorts by
     * title (case-insensitive); the count columns sort numerically. The page id
     * is a stable tiebreak in every case.
     *
     * @param array  $rows
     * @param string $sort  one of $this->sortable
     * @param string $dir   'asc' or 'desc'
     * @return array
     */
    protected function sortRows(array $rows, $sort, $dir)
    {
        usort($rows, static function ($a, $b) use ($sort) {
            if ($sort === 'normal' || $sort === 'resolved' || $sort === 'orphaned') {
                $cmp = $a[$sort] <=> $b[$sort];
            } else {
                $cmp = strcasecmp((string) $a['title'], (string) $b['title']);
            }
            if ($cmp === 0) {
                $cmp = strcasecmp((string) $a['id'], (string) $b['id']);
            }
            return $cmp;
        });
        if ($dir === 'desc') {
            $rows = array_reverse($rows);
        }
        return $rows;
    }

    /**
     * Slice the rows for the current page.
     *
     * @param array $rows    filtered + sorted rows
     * @param int   $perPage rows per page; <= 0 means "all on one page"
     * @return array [pageRows, page, totalPages, from, to] — from/to are 1-based
     *               row numbers of the slice (0 when there are no rows)
     */
    protected function paginate(array $rows, $perPage)
    {
        global $INPUT;
        $total = count($rows);

        if ($perPage <= 0) {
            return [$rows, 1, 1, $total > 0 ? 1 : 0, $total];
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = $INPUT->int('pg', 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $slice  = array_slice($rows, $offset, $perPage);
        $from   = $total > 0 ? $offset + 1 : 0;
        $to     = min($total, $offset + $perPage);

        return [$slice, $page, $totalPages, $from, $to];
    }

    // ---------------------------------------------------------------------
    //  Link / header / filter-row / pager helpers
    // ---------------------------------------------------------------------

    /**
     * The standing query parameters every in-table link and the clear-action
     * redirect must carry: the admin page id, the active sort + direction, the
     * page filter and the current page number. Read from $INPUT (which merges
     * GET and POST) so it works for the GET table links and the POST clear
     * forms alike.
     *
     * @param array $overrides applied last
     * @return array
     */
    protected function standingParams(array $overrides = [])
    {
        global $INPUT;
        $params = ['do' => 'admin', 'page' => 'annotations'];

        $sort = $INPUT->str('sort');
        if (in_array($sort, $this->sortable, true)) {
            $params['sort'] = $sort;
        }
        if ($INPUT->str('dir') === 'desc') {
            $params['dir'] = 'desc';
        }
        $filters = $this->activeFilters();
        if ($filters !== []) {
            $params['q'] = $filters;
        }
        $pg = $INPUT->int('pg', 0);
        if ($pg > 1) {
            $params['pg'] = $pg;
        }
        return array_merge($params, $overrides);
    }

    /**
     * Build an in-table URL back to this admin page.
     *
     * @param array $params full query parameters (incl. do/page)
     * @return string HTML-attribute-safe URL
     */
    protected function tableURL(array $params)
    {
        global $ID;
        return wl($ID, $params, false, '&amp;');
    }

    /**
     * Emit a column header. Sortable columns link to a re-sort (clicking the
     * active column flips the direction and resets to page 1); the actions
     * column is plain text. The active filter is preserved via standingParams.
     *
     * @param string $key   column key
     * @param string $label visible header text
     * @param string $sort  currently active sort column
     * @param string $dir   currently active direction
     * @return string
     */
    protected function headerCell($key, $label, $sort, $dir)
    {
        if (!in_array($key, $this->sortable, true)) {
            return '<th>' . hsc($label) . '</th>';
        }

        $newDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = '';
        if ($sort === $key) {
            // ▲ U+25B2 / ▼ U+25BC as HTML entities (concatenated raw, not hsc'd)
            $arrow = ($dir === 'asc') ? ' &#9650;' : ' &#9660;';
        }

        // wl() already returns an HTML-safe URL (its &amp; separator); it must
        // NOT be passed through hsc() or the ampersands double-encode. The
        // label is plain text and IS hsc()'d.
        $url = $this->tableURL($this->standingParams(['sort' => $key, 'dir' => $newDir, 'pg' => 1]));
        return '<th><a href="' . $url . '">' . hsc($label) . $arrow . '</a></th>';
    }

    /**
     * The per-column filter row: a text input under Page, empty cells under the
     * count columns (numbers are not filtered), and the Search/Clear controls
     * under Actions.
     *
     * @param string[] $cols    visible columns in order
     * @param array    $filters active filters
     * @param string   $sort
     * @param string   $dir
     * @return string
     */
    protected function renderFilterRow(array $cols, array $filters, $sort, $dir)
    {
        $html = '<tr class="annotations_admin_filterrow">';
        foreach ($cols as $c) {
            if ($c === 'page') {
                $val = isset($filters['page']) ? hsc($filters['page']) : '';
                $html .= '<td><input type="text" name="q[page]" class="edit" value="' . $val . '" /></td>';
            } elseif ($c === 'actions') {
                $html .= '<td class="annotations_admin_filteractions">';
                $html .= '<button type="submit" class="button">'
                       . hsc($this->getLang('filter_search')) . '</button>';
                if ($filters !== []) {
                    $clear = $this->tableURL([
                        'do'   => 'admin',
                        'page' => 'annotations',
                        'sort' => $sort,
                        'dir'  => $dir,
                    ]);
                    $html .= ' <a class="annotations_admin_clear" href="' . $clear . '">'
                           . hsc($this->getLang('filter_clear')) . '</a>';
                }
                $html .= '</td>';
            } else {
                $html .= '<td></td>';
            }
        }
        return $html . '</tr>';
    }

    /**
     * Render the numbered pager: « prev  1 … 4 [5] 6 … 20  next ». Empty string
     * when there is only one page. Links preserve sort + filter via standingParams.
     *
     * @param int $page
     * @param int $totalPages
     * @return string
     */
    protected function renderPager($page, $totalPages)
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav class="annotations_admin_pager" aria-label="' . hsc($this->getLang('pager_label')) . '">';

        if ($page > 1) {
            $html .= $this->pagerLink($page - 1, '&#8249;', 'pager_prev');
        } else {
            $html .= '<span class="pager_btn pager_disabled">&#8249;</span>';
        }

        foreach ($this->pageWindow($page, $totalPages) as $p) {
            if ($p === 0) {
                $html .= '<span class="pager_gap">&#8230;</span>';
            } elseif ($p === $page) {
                $html .= '<span class="pager_cur">' . $p . '</span>';
            } else {
                $html .= $this->pagerLink($p, (string) $p, '');
            }
        }

        if ($page < $totalPages) {
            $html .= $this->pagerLink($page + 1, '&#8250;', 'pager_next');
        } else {
            $html .= '<span class="pager_btn pager_disabled">&#8250;</span>';
        }

        return $html . '</nav>';
    }

    /**
     * One pager link (number or arrow), preserving sort + filter.
     *
     * @param int    $p        target page
     * @param string $text     already-safe link text (number or entity)
     * @param string $titleKey lang key for the title attribute, or '' for none
     * @return string
     */
    protected function pagerLink($p, $text, $titleKey)
    {
        $url   = $this->tableURL($this->standingParams(['pg' => $p]));
        $title = ($titleKey !== '') ? ' title="' . hsc($this->getLang($titleKey)) . '"' : '';
        return '<a class="pager_btn" href="' . $url . '"' . $title . '>' . $text . '</a>';
    }

    /**
     * Page numbers to show around the current page, with 0 marking an elided
     * gap. Always includes the first and last page.
     *
     * @param int $page
     * @param int $totalPages
     * @return int[]
     */
    protected function pageWindow($page, $totalPages)
    {
        $window = 2;
        $keep   = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || ($i >= $page - $window && $i <= $page + $window)) {
                $keep[] = $i;
            }
        }

        $out  = [];
        $prev = 0;
        foreach ($keep as $p) {
            if ($prev && ($p - $prev) > 1) {
                $out[] = 0; // gap marker
            }
            $out[] = $p;
            $prev  = $p;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    //  Clear-action POST forms
    // ---------------------------------------------------------------------

    /**
     * The four POST forms the clear buttons submit (via the HTML5 form= attr):
     * a single-page and a wiki-wide form for each of "clear resolved" and "clear
     * orphaned". For the single-page forms the page id arrives from the button's
     * value (name="clearpage"). Every form carries the CSRF token and the
     * standing sort/filter/page so the Post/Redirect/Get lands back on the same
     * view.
     *
     * @return string
     */
    protected function clearForms()
    {
        global $ID;
        $std   = $this->standingHiddenFields();
        $base  = '<input type="hidden" name="id" value="' . hsc($ID) . '" />';
        $base .= '<input type="hidden" name="do" value="admin" />';
        $base .= '<input type="hidden" name="page" value="annotations" />';

        return $this->clearForm('ann_clear_resolved_single', 'clear_resolved', $base, $std)
             . $this->clearForm('ann_clear_resolved_all', 'clear_resolved_all', $base, $std)
             . $this->clearForm('ann_clear_orphaned_single', 'clear_orphaned', $base, $std)
             . $this->clearForm('ann_clear_orphaned_all', 'clear_orphaned_all', $base, $std);
    }

    /**
     * One clear-action POST form, targeted by its buttons via the HTML5 form=
     * attribute.
     *
     * @param string $formId DOM id the buttons reference
     * @param string $action value of the annotations_action field
     * @param string $base   shared hidden fields (id/do/page)
     * @param string $std    standing sort/filter/page hidden fields
     * @return string
     */
    protected function clearForm($formId, $action, $base, $std)
    {
        $html  = '<form id="' . hsc($formId) . '" method="post" action="'
               . DOKU_BASE . DOKU_SCRIPT . '" class="annotations_admin_post">';
        $html .= formSecurityToken(false);
        $html .= $base;
        $html .= '<input type="hidden" name="annotations_action" value="' . hsc($action) . '" />';
        $html .= $std;
        $html .= '</form>';
        return $html;
    }

    /**
     * The standing sort/filter/page state as hidden inputs, so a clear action's
     * redirect (which rebuilds the URL from $INPUT) preserves the current view.
     *
     * @return string
     */
    protected function standingHiddenFields()
    {
        global $INPUT;
        $html = '';

        $sort = $INPUT->str('sort');
        if (in_array($sort, $this->sortable, true)) {
            $html .= '<input type="hidden" name="sort" value="' . hsc($sort) . '" />';
        }
        if ($INPUT->str('dir') === 'desc') {
            $html .= '<input type="hidden" name="dir" value="desc" />';
        }
        $filters = $this->activeFilters();
        if (isset($filters['page'])) {
            $html .= '<input type="hidden" name="q[page]" value="' . hsc($filters['page']) . '" />';
        }
        $pg = $INPUT->int('pg', 0);
        if ($pg > 1) {
            $html .= '<input type="hidden" name="pg" value="' . $pg . '" />';
        }
        return $html;
    }

    /**
     * An onclick attribute that confirms before submitting, escaped safely for
     * both the HTML-attribute and the JS-string layers (json_encode escapes the
     * quotes inside the message, hsc escapes the attribute).
     *
     * @param string $key lang key of the confirmation message
     * @return string e.g. ' onclick="return confirm(&quot;…&quot;)"'
     */
    protected function confirmAttr($key)
    {
        $js = 'return confirm('
            . json_encode($this->getLang($key), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
            . ');';
        return ' onclick="' . hsc($js) . '"';
    }
}
