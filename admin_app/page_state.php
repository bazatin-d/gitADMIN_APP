<?php
defined('ASR_ADMIN') || exit;

$current_tab = (isset($_GET['spam']) && $_GET['spam'] === 'blocked') ? 'results' : asr_admin_resolve_tab($_GET['tab'] ?? '');

// Если это публичная ссылка - принудительно открываем вид детализации
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : ($is_shared_view && count($shared_ids) === 1 ? $shared_ids[0] : null);
$compare_ids = isset($_GET['compare']) ? explode(',', $_GET['compare']) : ($is_shared_view && count($shared_ids) > 1 ? $shared_ids : []);
$is_detail_view = ($view_id || !empty($compare_ids));

// Значения по умолчанию нужны для модальных окон, которые подключаются на разных вкладках
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Функция генерации URL для пагинации
function pageUrl($p, $s) {
    return 'admin.php?page=' . (int)$p . ($s !== '' ? '&search=' . urlencode($s) : '');
}
