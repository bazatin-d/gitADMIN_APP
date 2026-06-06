<?php
defined('ASR_ADMIN') || exit;

/**
 * Repository модуля настроек.
 * Пока проксирует существующие функции из admin_app/lib/settings.php,
 * чтобы не ломать старые места проекта, где настройки уже используются.
 */

function asr_settings_repository_save(array $pairs): void {
    asr_save_settings($pairs);
}

function asr_settings_repository_all(): array {
    return asr_get_all_settings();
}
