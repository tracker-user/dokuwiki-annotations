<?php

/**
 * German language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// User-settings toggle (PHP side)
$lang['toggle_label'] = 'Anmerkungen aktivieren';
$lang['toggle_desc']  = 'Annotierten Text hervorheben und das Anmerkungsfeld auf Wiki-Seiten anzeigen.';

// Front-end strings (LANG.plugins.annotations.<key>)
$lang['js']['counter_annotation']    = '1 Anmerkung';
$lang['js']['counter_annotations']   = '%d Anmerkungen';
$lang['js']['counter_orphaned']      = '%d verwaist';

$lang['js']['btn_clear_resolved']    = 'Erledigte entfernen';
$lang['js']['btn_clear_orphaned']    = 'Verwaiste entfernen';
$lang['js']['btn_reply']             = 'Antworten';
$lang['js']['btn_resolve']           = 'Erledigen';
$lang['js']['btn_reopen']            = 'Wieder öffnen';
$lang['js']['btn_edit']              = 'Bearbeiten';
$lang['js']['btn_delete']            = 'Löschen';
$lang['js']['btn_save']              = 'Speichern';
$lang['js']['btn_cancel']            = 'Abbrechen';
$lang['js']['btn_annotate']          = 'Anmerken';

$lang['js']['status_open']           = 'Offen';
$lang['js']['status_resolved']       = 'Erledigt';

$lang['js']['placeholder_body']      = 'Kommentar hinzufügen…';
$lang['js']['placeholder_reply']     = 'Antwort schreiben…';

$lang['js']['orphaned_heading']      = 'Verwaiste Anmerkungen';
$lang['js']['orphaned_note']         = 'Diese Anmerkungen beziehen sich auf Text, der nicht mehr auf der Seite vorhanden ist.';
$lang['js']['orphaned_none']         = 'Keine.';

$lang['js']['label_close']           = 'Schließen';
$lang['js']['label_annotation']      = 'Anmerkung';
$lang['js']['label_unknown']         = 'Unbekannt';

$lang['js']['confirm_delete']        = 'Diese Anmerkung löschen?';
$lang['js']['confirm_delete_reply']  = 'Diese Antwort löschen?';
$lang['js']['confirm_clear_resolved'] = 'Alle erledigten Anmerkungen auf dieser Seite löschen?';
$lang['js']['confirm_clear_orphaned'] = 'Alle verwaisten Anmerkungen auf dieser Seite löschen?';

$lang['js']['error_save']            = 'Konnte nicht gespeichert werden – bitte erneut versuchen.';
$lang['js']['error_delete']          = 'Konnte nicht gelöscht werden – bitte erneut versuchen.';
$lang['js']['error_status']          = 'Status konnte nicht aktualisiert werden – bitte erneut versuchen.';
$lang['js']['error_clear']           = 'Konnte nicht entfernt werden – bitte erneut versuchen.';

$lang['js']['time_now']              = 'gerade eben';
$lang['js']['time_minutes']          = 'vor %d Min.';
$lang['js']['time_hours']            = 'vor %d Std.';
$lang['js']['time_days']             = 'vor %d T.';
