<?php

/**
 * English language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// ---------------------------------------------------------------------
// User-settings toggle
// ---------------------------------------------------------------------

/** @var string label shown on the preferences page for the on/off toggle */
$lang['toggle_label'] = 'Enable annotations';

/** @var string description shown below the toggle */
$lang['toggle_desc']  = 'Highlight annotated text and show the annotation panel on wiki pages.';

// ---------------------------------------------------------------------
// Page counter / toolbar
// ---------------------------------------------------------------------

/** @var string plural annotation counter, %d = count */
$lang['counter_annotations'] = '%d annotations';

/** @var string singular annotation counter */
$lang['counter_annotation']  = '1 annotation';

/** @var string orphaned annotation counter link, %d = count */
$lang['counter_orphaned']    = '%d orphaned';

/** @var string shown when there are no annotations on the page */
$lang['counter_none']        = 'No annotations';

/** @var string button: clear all resolved annotations (admin) */
$lang['btn_clear_resolved']  = 'Clear resolved';

/** @var string button: clear all orphaned annotations (admin) */
$lang['btn_clear_orphaned']  = 'Clear orphaned';

// ---------------------------------------------------------------------
// Annotation thread panel
// ---------------------------------------------------------------------

/** @var string button: open the reply form */
$lang['btn_reply']           = 'Reply';

/** @var string button: mark annotation as resolved */
$lang['btn_resolve']         = 'Resolve';

/** @var string button: reopen a resolved annotation */
$lang['btn_reopen']          = 'Reopen';

/** @var string button: edit annotation or reply body */
$lang['btn_edit']            = 'Edit';

/** @var string button: delete annotation or reply */
$lang['btn_delete']          = 'Delete';

/** @var string button: save an edit or new annotation/reply */
$lang['btn_save']            = 'Save';

/** @var string button: cancel edit/new-annotation form */
$lang['btn_cancel']          = 'Cancel';

/** @var string button: submit new annotation */
$lang['btn_annotate']        = 'Annotate';

// ---------------------------------------------------------------------
// Status pills
// ---------------------------------------------------------------------

/** @var string status pill shown on open annotations */
$lang['status_open']         = 'Open';

/** @var string status pill shown on resolved annotations */
$lang['status_resolved']     = 'Resolved';

// ---------------------------------------------------------------------
// Inline form placeholder text
// ---------------------------------------------------------------------

/** @var string placeholder text inside the body textarea */
$lang['placeholder_body']    = 'Add a comment…';

/** @var string placeholder text inside the reply textarea */
$lang['placeholder_reply']   = 'Write a reply…';

// ---------------------------------------------------------------------
// Selection tooltip
// ---------------------------------------------------------------------

/** @var string text shown in the tooltip bubble when the user selects text */
$lang['tooltip_annotate']    = 'Annotate selection';

// ---------------------------------------------------------------------
// Orphaned annotations section heading
// ---------------------------------------------------------------------

/** @var string heading for the orphaned-annotations section in the panel */
$lang['orphaned_heading']    = 'Orphaned annotations';

/** @var string explanatory note under the orphaned heading */
$lang['orphaned_note']       = 'These annotations reference text that no longer appears on the page.';

// ---------------------------------------------------------------------
// Error / confirmation messages
// ---------------------------------------------------------------------

/** @var string generic save error shown in the panel */
$lang['error_save']          = 'Could not save — please try again.';

/** @var string shown when the selection is too short to annotate */
$lang['error_selection']     = 'Please select some text to annotate.';

/** @var string confirmation prompt before deleting an annotation */
$lang['confirm_delete']      = 'Delete this annotation?';

/** @var string confirmation prompt before clearing resolved annotations */
$lang['confirm_clear_resolved'] = 'Delete all resolved annotations on this page?';

/** @var string confirmation prompt before clearing orphaned annotations */
$lang['confirm_clear_orphaned'] = 'Delete all orphaned annotations on this page?';
