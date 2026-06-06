<?php
defined('ASR_ADMIN') || exit;

/**
 * Сервис модуля писем.
 * Здесь держим список email/SMTP-настроек и подготовку данных формы.
 */

function asr_emails_setting_keys(): array {
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

function asr_emails_collect_settings(array $post): array {
    $pairs = [];
    foreach (asr_emails_setting_keys() as $key) {
        $pairs[$key] = trim((string)($post[$key] ?? ''));
    }
    return $pairs;
}

function asr_emails_template_vars_hint(): string {
    return '{{name}}, {{phone}}, {{email}}, {{city}}, {{role}}, {{date}}, {{manager_link}}, {{client_link}}, {{resume_link}}, {{bitrix_deal_block}}';
}

function asr_emails_access_vars_hint(): string {
    return '{{full_name}}, {{username}}, {{login}}, {{password}}, {{role}}, {{admin_url}}';
}
