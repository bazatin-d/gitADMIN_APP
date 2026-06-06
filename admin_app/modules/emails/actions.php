<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/repository.php';

/**
 * POST-действия модуля писем и SMTP.
 * Подключается через admin_app/actions/dispatcher.php до legacy admin.php.
 */

if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_email_settings') {
    try {
        asr_emails_repository_save(asr_emails_collect_settings($_POST));
        header('Location: admin.php?tab=emails&saved=1');
    } catch (Throwable $e) {
        header('Location: admin.php?tab=emails&email_error=' . urlencode('Настройки писем не сохранены'));
    }
    exit;
}
