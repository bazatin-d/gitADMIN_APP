<?php
/**
 * Совместимый вход для старых подключений меню.
 * Реальный код живёт в admin_app/modules/menu/.
 */

defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/../modules/menu/service.php';
require_once __DIR__ . '/../modules/menu/repository.php';
