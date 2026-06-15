<?php

/**
 * Russian language strings for the annotations plugin.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

// User-settings toggle (PHP side)
$lang['toggle_label'] = 'Включить аннотации';
$lang['toggle_desc']  = 'Подсвечивать аннотированный текст и показывать панель аннотаций на страницах вики.';

// Обзор для администратора (PHP-сторона)
$lang['menu']               = 'Аннотации';
$lang['heading']            = 'Аннотированные страницы';
$lang['intro']              = 'Страницы с аннотациями и количеством обычных и потерянных аннотаций. Потерянные аннотации ссылаются на текст, которого больше нет на странице.';

$lang['th_page']            = 'Страница';
$lang['th_normal']          = 'Обычные';
$lang['th_orphaned']        = 'Потерянные';
$lang['th_actions']         = 'Действия';

$lang['filter_search']      = 'Поиск';
$lang['filter_clear']       = 'Сбросить';

$lang['btn_clear_orphaned'] = 'Очистить потерянные';
$lang['btn_clear_all']      = 'Очистить все потерянные (%d)';

$lang['confirm_clear_page'] = 'Удалить все потерянные аннотации на этой странице?';
$lang['confirm_clear_all']  = 'Удалить все потерянные аннотации на всех страницах?';

$lang['cleared_page']       = 'Удалено потерянных аннотаций: %d со страницы %s.';
$lang['cleared_all']        = 'Удалено потерянных аннотаций на всех страницах: %d.';
$lang['clear_fail']         = 'Не удалось очистить потерянные аннотации.';

$lang['none']               = 'Ни на одной странице нет аннотаций.';
$lang['none_match']         = 'Нет страниц, соответствующих вашему запросу.';
$lang['shown']              = 'Показаны %d–%d из %d';

$lang['pager_label']        = 'Постраничная навигация';
$lang['pager_prev']         = 'Предыдущая страница';
$lang['pager_next']         = 'Следующая страница';

$lang['helper_missing']     = 'Вспомогательный модуль аннотаций недоступен.';

// Front-end strings (LANG.plugins.annotations.<key>)
$lang['js']['counter_annotation']    = '1 аннотация';
$lang['js']['counter_annotations']   = '%d аннотаций';
$lang['js']['counter_orphaned']      = '%d потерянных';

$lang['js']['btn_clear_resolved']    = 'Очистить решённые';
$lang['js']['btn_clear_orphaned']    = 'Очистить потерянные';
$lang['js']['btn_reply']             = 'Ответить';
$lang['js']['btn_resolve']           = 'Решить';
$lang['js']['btn_reopen']            = 'Открыть заново';
$lang['js']['btn_edit']              = 'Изменить';
$lang['js']['btn_delete']            = 'Удалить';
$lang['js']['btn_save']              = 'Сохранить';
$lang['js']['btn_cancel']            = 'Отмена';
$lang['js']['btn_annotate']          = 'Аннотировать';

$lang['js']['status_open']           = 'Открыто';
$lang['js']['status_resolved']       = 'Решено';

$lang['js']['placeholder_body']      = 'Добавить комментарий…';
$lang['js']['placeholder_reply']     = 'Написать ответ…';

$lang['js']['orphaned_heading']      = 'Потерянные аннотации';
$lang['js']['orphaned_note']         = 'Эти аннотации ссылаются на текст, которого больше нет на странице.';
$lang['js']['orphaned_none']         = 'Нет.';

$lang['js']['label_close']           = 'Закрыть';
$lang['js']['label_annotation']      = 'Аннотация';
$lang['js']['label_unknown']         = 'Неизвестно';

$lang['js']['confirm_delete']        = 'Удалить эту аннотацию?';
$lang['js']['confirm_delete_reply']  = 'Удалить этот ответ?';
$lang['js']['confirm_clear_resolved'] = 'Удалить все решённые аннотации на этой странице?';
$lang['js']['confirm_clear_orphaned'] = 'Удалить все потерянные аннотации на этой странице?';

$lang['js']['error_save']            = 'Не удалось сохранить — попробуйте ещё раз.';
$lang['js']['error_delete']          = 'Не удалось удалить — попробуйте ещё раз.';
$lang['js']['error_status']          = 'Не удалось обновить статус — попробуйте ещё раз.';
$lang['js']['error_clear']           = 'Не удалось очистить — попробуйте ещё раз.';

$lang['js']['time_now']              = 'только что';
$lang['js']['time_minutes']          = '%d мин. назад';
$lang['js']['time_hours']            = '%d ч. назад';
$lang['js']['time_days']             = '%d дн. назад';
