<?php
defined('ASR_ADMIN') || exit;

/**
 * Репозиторий модуля писем.
 * Низкоуровневое хранение пока использует общую библиотеку настроек.
 */

function asr_emails_repository_save(array $pairs): void {
    asr_save_settings($pairs);
}
