<?php
/**
 * Настройки админки АСР.
 * Работает мягко: если таблица oca_settings ещё не создана, возвращает безопасные значения по умолчанию.
 */

defined('ASR_ADMIN') || define('ASR_ADMIN', true);

function asr_setting_defaults(): array {
    return [
        'mail_host' => defined('ASR_DEFAULT_MAIL_HOST') ? ASR_DEFAULT_MAIL_HOST : '',
        'mail_port' => '465',
        'mail_secure' => 'ssl',
        'mail_username' => defined('ASR_DEFAULT_MAIL_USERNAME') ? ASR_DEFAULT_MAIL_USERNAME : '',
        'mail_password' => '@123avm123@',
        'mail_from_name' => 'Академия OCA',
        'notification_emails' => 'a.bazatina@yandex.ru',
        'notification_subject' => 'Новый тест OCA: {{name}}',
        'notification_body' => "Поступили результаты нового теста!\n\nИмя: {{name}}\nТелефон: {{phone}}\nEmail: {{email}}\nГород: {{city}}\nРоль: {{role}}\nДата: {{date}}\n\nСсылка на результат в админке:\n{{manager_link}}\n\nСсылка для респондента:\n{{client_link}}\n\n{{bitrix_deal_block}}",
        'resume_email_subject' => 'Продолжите тест АСР',
        'resume_email_body' => "Здравствуйте, {{name}}!\n\nВы начали проходить тест АСР, но пока не завершили его.\n\nПродолжить можно по этой ссылке:\n{{resume_link}}\n\nСсылка откроет тест с того места, где вы остановились.",
        'client_graph_email_subject' => 'Ваш результат теста АСР',
        'client_graph_email_body' => "Здравствуйте, {{name}}!\n\nВаш результат теста АСР готов.\n\nОткрыть график можно по ссылке:\n{{client_link}}\n\nНа смартфоне можно нажать на график, открыть изображение отдельно, увеличить его пальцами или сохранить в галерею.",
        'agreement_url' => function_exists('asr_config_agreement_url') ? asr_config_agreement_url() : '/agreement.html',
        'access_share_message' => "Здравствуйте, {{full_name}}!\n\nДля вас создан доступ в админку системы тестирования АВМ.\n\nАдрес входа: {{admin_url}}\nЛогин: {{username}}\nПароль: {{password}}\nРоль: {{role}}\n\nСохраните доступы в надёжном месте. Не в стикере на мониторе — он уже всё видел.",
        'b24_webhook_url' => 'https://exec-booster.bitrix24.kz/rest/11/5lh1nh17l56ummxs/',
        'b24_portal_url' => 'https://exec-booster.bitrix24.kz',
        'b24_deal_category_id' => '13',
        'b24_deal_stage_id' => 'C13:NEW',
        'b24_deal_stage_partial_id' => 'C13:UC_VENTXV',
        'b24_uf_test_manager' => 'UF_CRM_1778755158529',
        'b24_uf_test_client' => 'UF_CRM_1779105865452',
        'b24_uf_resume_link' => 'UF_CRM_1779782130788',
        'b24_uf_test_date' => 'UF_CRM_1778818756245',
        'b24_uf_deal_city' => 'UF_CRM_1709107813313',
        'b24_uf_deal_role' => 'UF_CRM_1778819338182',
        'b24_uf_contact_city' => 'UF_CRM_1709108400679',
        'b24_debug' => '0',
        'app_html_title' => 'Система тестирования АВМ',
        'app_name' => 'АСР АВМ',
        'app_header_title' => 'СИСТЕМА ТЕСТИРОВАНИЯ АВМ',
        'help_video_admin' => '',
        'help_video_manager' => '',
        'help_video_operator' => '',
        'help_content' => '<div style="font-family: Montserrat, Arial, sans-serif; line-height: 1.65;"><h2>Справочная информация</h2><p>Здесь можно разместить инструкции для сотрудников: как читать график, как отправлять ссылку респонденту, как работать с незавершёнными тестами.</p></div>',
        'shortener_instruction' => '<p><strong>Как пользоваться короткими ссылками:</strong> вставьте длинную ссылку, выберите домен и нажмите «Сгенерировать». Если в ссылке есть UTM-метки и якорь, система сама приведёт адрес к правильному виду: сначала параметры, потом якорь.</p><p>После редактирования хвостика старая короткая ссылка перестанет работать. Меняйте её только если ссылка ещё не ушла в рассылку или рекламу.</p>',
        'telegram_bot_token' => '',
        'telegram_bot_username' => '',
        'telegram_webhook_secret' => '',
        // Оповещения Telegram из модуля «Доступы». Напоминания об оплатах не зависят от этих галочек.
        'access_vault_tg_notifications_disabled' => '0',
        'access_vault_tg_notify_create' => '1',
        'access_vault_tg_notify_delete' => '1',
        'access_vault_tg_notify_update' => '1',
        'access_vault_payment_notification_message' => "Здравствуйте, {{name_user}}!\n\nНужно оплатить ресурс.\nСумма: {{pay}}\nДата оплаты: {{date_pay}}\nПериод: {{period}}",
    ];
}

function asr_settings_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'oca_settings'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

function asr_get_setting(string $key, $default = null) {
    global $pdo;
    $defaults = asr_setting_defaults();
    $fallback = $default !== null ? $default : ($defaults[$key] ?? '');

    if (!isset($pdo) || !($pdo instanceof PDO) || !asr_settings_table_exists($pdo)) {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM oca_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function asr_get_all_settings(): array {
    global $pdo;
    $settings = asr_setting_defaults();
    if (!isset($pdo) || !($pdo instanceof PDO) || !asr_settings_table_exists($pdo)) {
        return $settings;
    }
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM oca_settings");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {}
    return $settings;
}

function asr_save_settings(array $pairs): void {
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Нет подключения к БД.');
    }
    if (!asr_settings_table_exists($pdo)) {
        throw new RuntimeException('Таблица oca_settings не найдена. Сначала выполните migration_admin_settings.sql');
    }
    $stmt = $pdo->prepare("INSERT INTO oca_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    foreach ($pairs as $key => $value) {
        $stmt->execute([$key, (string)$value]);
    }
}

function asr_normalize_emails(string $raw): array {
    $items = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $emails = [];
    foreach ($items as $item) {
        $item = trim($item);
        if (filter_var($item, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $item;
        }
    }
    return array_values(array_unique($emails));
}

function asr_render_template(string $template, array $vars): string {
    $replace = [];
    foreach ($vars as $key => $value) {
        $replace['{{' . $key . '}}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function asr_clean_admin_html(string $html): string {
    $html = preg_replace('#<\s*script[^>]*>.*?<\s*/\s*script\s*>#isu', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html);
    $html = preg_replace('/javascript\s*:/iu', '', $html);
    return trim($html);
}

function asr_current_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return function_exists('asr_config_app_base_url') ? asr_config_app_base_url() : ($scheme . '://' . 'localhost' . '/');
}

function asr_smtp_config_from_settings(array $settings): array {
    return [
        'host' => trim((string)($settings['mail_host'] ?? '')),
        'port' => (int)($settings['mail_port'] ?? 465),
        'secure' => trim((string)($settings['mail_secure'] ?? 'ssl')),
        'username' => trim((string)($settings['mail_username'] ?? '')),
        'password' => (string)($settings['mail_password'] ?? ''),
        'from_name' => trim((string)($settings['mail_from_name'] ?? 'Академия OCA')),
    ];
}

function asr_smtp_read($socket): string {
    $data = '';
    while ($line = fgets($socket, 515)) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function asr_smtp_command($socket, string $command): string {
    fwrite($socket, $command . "\r\n");
    return asr_smtp_read($socket);
}

function asr_send_smtp_mail(string $to, string $subject, string $message, array $config): bool {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (empty($config['host']) || empty($config['port']) || empty($config['username'])) {
        return false;
    }

    $domain = parse_url('https://' . $config['host'], PHP_URL_HOST) ?: 'localhost';
    $message_id = '<' . md5(uniqid((string)time(), true)) . '@' . $domain . '>';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($config['from_name'] ?: 'Академия OCA') . '?=';

    $headers  = "To: {$to}\r\n";
    $headers .= "From: {$encodedFromName} <{$config['username']}>\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: {$message_id}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $host = (($config['secure'] ?? '') === 'ssl') ? 'ssl://' . $config['host'] : $config['host'];
    $socket = @fsockopen($host, (int)$config['port'], $errno, $errstr, 15);
    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 15);
    asr_smtp_read($socket);
    asr_smtp_command($socket, 'EHLO ' . $domain);

    if (($config['secure'] ?? '') === 'tls') {
        asr_smtp_command($socket, 'STARTTLS');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        asr_smtp_command($socket, 'EHLO ' . $domain);
    }

    asr_smtp_command($socket, 'AUTH LOGIN');
    asr_smtp_command($socket, base64_encode($config['username']));
    asr_smtp_command($socket, base64_encode($config['password']));
    asr_smtp_command($socket, 'MAIL FROM:<' . $config['username'] . '>');
    asr_smtp_command($socket, 'RCPT TO:<' . $to . '>');
    asr_smtp_command($socket, 'DATA');
    fwrite($socket, $headers . "\r\n" . str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
    $dataResponse = asr_smtp_read($socket);
    asr_smtp_command($socket, 'QUIT');
    fclose($socket);

    return preg_match('/^250\b/m', $dataResponse) === 1;
}

function asr_result_resume_url(array $r): string {
    $token = trim((string)($r['resume_token'] ?? ''));
    if ($token === '') return '';
    return asr_current_base_url() . '?resume=' . rawurlencode($token);
}

function asr_result_shared_url(array $r): string {
    if (!function_exists('encodeId')) return '';
    return asr_current_base_url() . 'admin.php?shared=' . encodeId((int)$r['id']);
}

function asr_result_manager_url(array $r): string {
    return asr_current_base_url() . 'admin.php?view=' . (int)$r['id'];
}

function asr_result_template_vars(array $r): array {
    return [
        'name' => (string)($r['name'] ?? ''),
        'phone' => (string)($r['phone'] ?? ''),
        'email' => (string)($r['email'] ?? ''),
        'city' => (string)($r['city'] ?? ''),
        'role' => (string)($r['role'] ?? ''),
        'date' => !empty($r['created_at']) ? date('d.m.Y H:i', strtotime((string)$r['created_at'])) : date('d.m.Y H:i'),
        'manager_link' => asr_result_manager_url($r),
        'client_link' => asr_result_shared_url($r),
        'resume_link' => asr_result_resume_url($r),
        'bitrix_deal_block' => !empty($r['crm_deal']) ? "Сделка в Битрикс24:\n" . $r['crm_deal'] . "\n" : '',
    ];
}

