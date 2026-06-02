<?php

/**
 * English language strings for the annotations plugin.
 *
 * Two groups:
 *   - top-level $lang[...]  : read by PHP via $this->getLang() (the usersettings toggle).
 *   - $lang['js'][...]      : exposed to script.js by DokuWiki as
 *                             LANG.plugins.annotations.<key> (see lib/exe/js.php).
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// ---------------------------------------------------------------------
// User-settings toggle (PHP side)
// ---------------------------------------------------------------------

/** @var string label shown on the preferences page for the on/off toggle */
$lang['toggle_label'] = 'Enable annotations';

/** @var string description shown below the toggle */
$lang['toggle_desc']  = 'Highlight annotated text and show the annotation panel on wiki pages.';

// ---------------------------------------------------------------------
// Front-end strings (exposed as LANG.plugins.annotations.<key>)
// ---------------------------------------------------------------------

// Counter bar
$lang['js']['counter_annotation']    = '1 annotation';
$lang['js']['counter_annotations']   = '%d annotations';
$lang['js']['counter_orphaned']      = '%d orphaned';

// Buttons
$lang['js']['btn_clear_resolved']    = 'Clear resolved';
$lang['js']['btn_clear_orphaned']    = 'Clear orphaned';
$lang['js']['btn_reply']             = 'Reply';
$lang['js']['btn_resolve']           = 'Resolve';
$lang['js']['btn_reopen']            = 'Reopen';
$lang['js']['btn_edit']              = 'Edit';
$lang['js']['btn_delete']            = 'Delete';
$lang['js']['btn_save']              = 'Save';
$lang['js']['btn_cancel']            = 'Cancel';
$lang['js']['btn_annotate']          = 'Annotate';

// Status pills
$lang['js']['status_open']           = 'Open';
$lang['js']['status_resolved']       = 'Resolved';

// Form placeholders
$lang['js']['placeholder_body']      = 'Add a comment…';
$lang['js']['placeholder_reply']     = 'Write a reply…';

// Orphaned-annotations drawer
$lang['js']['orphaned_heading']      = 'Orphaned annotations';
$lang['js']['orphaned_note']         = 'These annotations reference text that no longer appears on the page.';
$lang['js']['orphaned_none']         = 'None.';

// Accessible labels / fallbacks
$lang['js']['label_close']           = 'Close';
$lang['js']['label_annotation']      = 'Annotation';
$lang['js']['label_unknown']         = 'Unknown';

// Confirmation prompts
$lang['js']['confirm_delete']        = 'Delete this annotation?';
$lang['js']['confirm_delete_reply']  = 'Delete this reply?';
$lang['js']['confirm_clear_resolved'] = 'Delete all resolved annotations on this page?';
$lang['js']['confirm_clear_orphaned'] = 'Delete all orphaned annotations on this page?';

// Error messages
$lang['js']['error_save']            = 'Could not save — please try again.';
$lang['js']['error_delete']          = 'Could not delete — please try again.';
$lang['js']['error_status']          = 'Could not update the status — please try again.';
$lang['js']['error_clear']           = 'Could not clear — please try again.';

// Relative timestamps (%d = number)
$lang['js']['time_now']              = 'just now';
$lang['js']['time_minutes']          = '%dm ago';
$lang['js']['time_hours']            = '%dh ago';
$lang['js']['time_days']             = '%dd ago';
