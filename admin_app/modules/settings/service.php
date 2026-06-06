<?php
defined('ASR_ADMIN') || exit;

/**
 * Сервис модуля настроек.
 * Здесь остаётся сбор и очистка данных формы, а низкоуровневые функции
 * хранения настроек пока живут в admin_app/lib/settings.php для совместимости.
 */

function asr_settings_general_keys(): array {
    return [
        'b24_webhook_url',
        'b24_portal_url',
        'b24_deal_category_id',
        'b24_deal_stage_id',
        'b24_deal_stage_partial_id',
        'b24_uf_test_manager',
        'b24_uf_test_client',
        'b24_uf_resume_link',
        'b24_uf_test_date',
        'b24_uf_deal_city',
        'b24_uf_deal_role',
        'b24_uf_contact_city',
        'app_html_title',
        'app_name',
        'app_header_title',
        'telegram_bot_token',
        'telegram_bot_username',
        'telegram_webhook_secret',
        'telegram_broadcast_test_bot_token',
        'telegram_broadcast_test_bot_username',
        'telegram_broadcast_test_webhook_secret',
        'access_vault_payment_notification_message',
    ];
}

function asr_settings_email_keys(): array {
    return [
        'mail_host',
        'mail_port',
        'mail_secure',
        'mail_username',
        'mail_password',
        'mail_from_name',
        'notification_emails',
        'notification_subject',
        'notification_body',
        'resume_email_subject',
        'resume_email_body',
        'client_graph_email_subject',
        'client_graph_email_body',
        'access_share_message',
    ];
}

function asr_settings_notification_keys(): array {
    return [
        'notification_emails',
        'notification_subject',
        'notification_body',
        'resume_email_subject',
        'resume_email_body',
        'client_graph_email_subject',
        'client_graph_email_body',
        'access_share_message',
    ];
}

function asr_settings_collect_general_pairs(array $post): array {
    $pairs = [];

    foreach (asr_settings_general_keys() as $key) {
        $pairs[$key] = trim((string)($post[$key] ?? ''));
    }

    $pairs['b24_debug'] = isset($post['b24_debug']) ? '1' : '0';

    // Эти галочки управляют Telegram-оповещениями из модуля «Доступы».
    // Их может менять только суперадминистратор; обычный админ при сохранении настроек
    // не должен случайно сбросить значения, потому что disabled-чекбоксы не уходят в POST.
    $canManageAccessTelegramNotifications = function_exists('asr_is_protected_user')
        ? asr_is_protected_user(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => function_exists('asr_current_role') ? asr_current_role() : ''])
        : ((function_exists('asr_current_role') ? asr_current_role() : '') === 'superadmin');
    if ($canManageAccessTelegramNotifications) {
        $pairs['access_vault_tg_notifications_disabled'] = isset($post['access_vault_tg_notifications_disabled']) ? '1' : '0';
        $pairs['access_vault_tg_notify_create'] = isset($post['access_vault_tg_notify_create']) ? '1' : '0';
        $pairs['access_vault_tg_notify_delete'] = isset($post['access_vault_tg_notify_delete']) ? '1' : '0';
        $pairs['access_vault_tg_notify_update'] = isset($post['access_vault_tg_notify_update']) ? '1' : '0';
    }

    $pairs['help_video_admin'] = asr_clean_admin_html((string)($post['help_video_admin'] ?? ''));
    $pairs['help_video_manager'] = asr_clean_admin_html((string)($post['help_video_manager'] ?? ''));
    $pairs['help_video_operator'] = asr_clean_admin_html((string)($post['help_video_operator'] ?? ''));
    $pairs['help_content'] = asr_clean_admin_html((string)($post['help_content'] ?? ''));
    $pairs['shortener_instruction'] = asr_clean_admin_html((string)($post['shortener_instruction'] ?? ''));

    foreach (asr_settings_notification_keys() as $key) {
        $pairs[$key] = trim((string)($post[$key] ?? ''));
    }

    return $pairs;
}

function asr_settings_collect_email_pairs(array $post): array {
    $pairs = [];
    foreach (asr_settings_email_keys() as $key) {
        $pairs[$key] = trim((string)($post[$key] ?? ''));
    }
    return $pairs;
}
