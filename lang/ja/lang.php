<?php

/**
 * Japanese language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// User-settings toggle (PHP side)
$lang['toggle_label'] = '注釈を有効にする';
$lang['toggle_desc']  = '注釈の付いたテキストを強調表示し、ウィキページに注釈パネルを表示します。';

// Front-end strings (LANG.plugins.annotations.<key>)
$lang['js']['counter_annotation']    = '注釈 1 件';
$lang['js']['counter_annotations']   = '注釈 %d 件';
$lang['js']['counter_orphaned']      = '孤立 %d 件';

$lang['js']['btn_clear_resolved']    = '解決済みを削除';
$lang['js']['btn_clear_orphaned']    = '孤立を削除';
$lang['js']['btn_reply']             = '返信';
$lang['js']['btn_resolve']           = '解決';
$lang['js']['btn_reopen']            = '再開';
$lang['js']['btn_edit']              = '編集';
$lang['js']['btn_delete']            = '削除';
$lang['js']['btn_save']              = '保存';
$lang['js']['btn_cancel']            = 'キャンセル';
$lang['js']['btn_annotate']          = '注釈を付ける';

$lang['js']['status_open']           = '未解決';
$lang['js']['status_resolved']       = '解決済み';

$lang['js']['placeholder_body']      = 'コメントを追加…';
$lang['js']['placeholder_reply']     = '返信を入力…';

$lang['js']['orphaned_heading']      = '孤立した注釈';
$lang['js']['orphaned_note']         = 'これらの注釈は、ページ上にもう存在しないテキストを参照しています。';
$lang['js']['orphaned_none']         = 'なし。';

$lang['js']['label_close']           = '閉じる';
$lang['js']['label_annotation']      = '注釈';
$lang['js']['label_unknown']         = '不明';

$lang['js']['confirm_delete']        = 'この注釈を削除しますか？';
$lang['js']['confirm_delete_reply']  = 'この返信を削除しますか？';
$lang['js']['confirm_clear_resolved'] = 'このページの解決済みの注釈をすべて削除しますか？';
$lang['js']['confirm_clear_orphaned'] = 'このページの孤立した注釈をすべて削除しますか？';

$lang['js']['error_save']            = '保存できませんでした。もう一度お試しください。';
$lang['js']['error_delete']          = '削除できませんでした。もう一度お試しください。';
$lang['js']['error_status']          = 'ステータスを更新できませんでした。もう一度お試しください。';
$lang['js']['error_clear']           = '削除できませんでした。もう一度お試しください。';

$lang['js']['time_now']              = 'たった今';
$lang['js']['time_minutes']          = '%d 分前';
$lang['js']['time_hours']            = '%d 時間前';
$lang['js']['time_days']             = '%d 日前';
