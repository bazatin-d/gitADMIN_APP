<?php
/**
 * Точка входа в админ-панель АСР/OCA.
 * Основная логика разнесена по admin_app, чтобы файл не превращался в склад с проводами.
 */
define('ASR_ADMIN', true);

require_once __DIR__ . '/admin_app/bootstrap.php';
require_once __DIR__ . '/admin_app/actions/pre_auth.php';

// Если не публичная ссылка и не авторизован — показываем форму входа
if (!$is_shared_view && !isset($_SESSION['logged_in'])) {
    require __DIR__ . '/admin_app/views/login.php';
    exit;
}

require_once __DIR__ . '/admin_app/actions/dispatcher.php';
require_once __DIR__ . '/admin_app/page_state.php';
require __DIR__ . '/admin_app/views/layout.php';
