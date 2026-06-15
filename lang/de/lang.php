<?php

/**
 * German language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// User-settings toggle (PHP side)
$lang['toggle_label'] = 'Anmerkungen aktivieren';
$lang['toggle_desc']  = 'Annotierten Text hervorheben und das Anmerkungsfeld auf Wiki-Seiten anzeigen.';

// Admin-Übersicht (PHP-Seite)
$lang['menu']               = 'Anmerkungen';
$lang['heading']            = 'Annotierte Seiten';
$lang['intro']              = 'Seiten mit Anmerkungen sowie deren normale, erledigte und verwaiste Anzahl. Erledigte Anmerkungen wurden als erledigt markiert; verwaiste Anmerkungen beziehen sich auf Text, der nicht mehr auf der Seite vorhanden ist. Die Zählungen überschneiden sich – eine erledigte Anmerkung wird je nachdem, ob ihr Text noch vorhanden ist, auch als normal oder verwaist gezählt.';

$lang['th_page']            = 'Seite';
$lang['th_normal']          = 'Normal';
$lang['th_resolved']        = 'Erledigt';
$lang['th_orphaned']        = 'Verwaist';
$lang['th_actions']         = 'Aktionen';

$lang['filter_search']      = 'Suchen';
$lang['filter_clear']       = 'Zurücksetzen';

$lang['btn_clear_orphaned']      = 'Verwaiste entfernen';
$lang['btn_clear_all']           = 'Alle verwaisten entfernen (%d)';
$lang['btn_clear_resolved']      = 'Erledigte entfernen';
$lang['btn_clear_resolved_all']  = 'Alle erledigten entfernen (%d)';

$lang['confirm_clear_page']          = 'Alle verwaisten Anmerkungen auf dieser Seite löschen?';
$lang['confirm_clear_all']           = 'Alle verwaisten Anmerkungen auf allen Seiten löschen?';
$lang['confirm_clear_resolved_page'] = 'Alle erledigten Anmerkungen auf dieser Seite löschen?';
$lang['confirm_clear_resolved_all']  = 'Alle erledigten Anmerkungen auf allen Seiten löschen?';

$lang['cleared_page']           = '%d verwaiste Anmerkung(en) von %s entfernt.';
$lang['cleared_all']            = '%d verwaiste Anmerkung(en) auf allen Seiten entfernt.';
$lang['clear_fail']             = 'Verwaiste Anmerkungen konnten nicht entfernt werden.';
$lang['cleared_resolved_page']  = '%d erledigte Anmerkung(en) von %s entfernt.';
$lang['cleared_resolved_all']   = '%d erledigte Anmerkung(en) auf allen Seiten entfernt.';
$lang['clear_resolved_fail']    = 'Erledigte Anmerkungen konnten nicht entfernt werden.';

$lang['none']               = 'Keine Seite hat Anmerkungen.';
$lang['none_match']         = 'Keine Seite entspricht Ihrer Suche.';
$lang['shown']              = 'Zeige %d–%d von %d';

$lang['pager_label']        = 'Seitennummerierung';
$lang['pager_prev']         = 'Vorherige Seite';
$lang['pager_next']         = 'Nächste Seite';

$lang['helper_missing']     = 'Der Annotations-Helfer ist nicht verfügbar.';

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
