<?php
defined('ASR_ADMIN') || exit;

/**
 * Центральный диспетчер действий админки.
 *
 * Действия подключаются из манифестов модулей:
 * admin_app/modules/{module}/module.php → 'actions'.
 *
 * Правило разработки:
 * - новый функционал добавляем в admin_app/modules/{module}/actions.php;
 * - admin_app/actions/admin.php больше не раздуваем.
 */

require_once __DIR__ . '/../core/modules.php';

// Все POST-действия внутри авторизованной админки требуют CSRF-токен.
if (!$is_shared_view && isset($_SESSION['logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    asr_require_csrf();
}

foreach (asr_admin_action_files() as $asr_action_module) {
    require_once $asr_action_module;
}

// Совместимая legacy-точка. Сейчас файл почти пустой и нужен только чтобы старые подключения не ломались.
require_once __DIR__ . '/admin.php';
