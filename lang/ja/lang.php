<?php

/**
 * Japanese language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// User-settings toggle (PHP side)
$lang['toggle_label'] = '注釈を有効にする';
$lang['toggle_desc']  = '注釈の付いたテキストを強調表示し、ウィキページに注釈パネルを表示します。';

// 管理者向け一覧（PHP 側）
$lang['menu']               = '注釈';
$lang['heading']            = '注釈のあるページ';
$lang['intro']              = '注釈のあるページと、その通常・解決済み・孤立の件数の一覧です。解決済みの注釈は完了として印が付けられており、孤立した注釈は、ページ上にもう存在しないテキストを参照しています。件数は重複します。解決済みの注釈は、そのテキストがページ上に残っているかどうかに応じて、通常または孤立としても数えられます。';

$lang['th_page']            = 'ページ';
$lang['th_normal']          = '通常';
$lang['th_resolved']        = '解決済み';
$lang['th_orphaned']        = '孤立';
$lang['th_actions']         = '操作';

$lang['filter_search']      = '検索';
$lang['filter_clear']       = 'クリア';

$lang['btn_clear_orphaned']      = '孤立を削除';
$lang['btn_clear_all']           = 'すべての孤立を削除 (%d)';
$lang['btn_clear_resolved']      = '解決済みを削除';
$lang['btn_clear_resolved_all']  = 'すべての解決済みを削除 (%d)';

$lang['confirm_clear_page']          = 'このページの孤立した注釈をすべて削除しますか？';
$lang['confirm_clear_all']           = 'すべてのページの孤立した注釈を削除しますか？';
$lang['confirm_clear_resolved_page'] = 'このページの解決済みの注釈をすべて削除しますか？';
$lang['confirm_clear_resolved_all']  = 'すべてのページの解決済みの注釈を削除しますか？';

$lang['cleared_page']           = '%d 件の孤立した注釈を %s から削除しました。';
$lang['cleared_all']            = 'すべてのページから %d 件の孤立した注釈を削除しました。';
$lang['clear_fail']             = '孤立した注釈を削除できませんでした。';
$lang['cleared_resolved_page']  = '%d 件の解決済みの注釈を %s から削除しました。';
$lang['cleared_resolved_all']   = 'すべてのページから %d 件の解決済みの注釈を削除しました。';
$lang['clear_resolved_fail']    = '解決済みの注釈を削除できませんでした。';

$lang['none']               = '注釈のあるページはありません。';
$lang['none_match']         = '検索条件に一致するページはありません。';
$lang['shown']              = '%d–%d / %d 件を表示';

$lang['pager_label']        = 'ページ送り';
$lang['pager_prev']         = '前のページ';
$lang['pager_next']         = '次のページ';

$lang['helper_missing']     = '注釈ヘルパーを利用できません。';

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
