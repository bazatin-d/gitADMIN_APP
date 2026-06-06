<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/crypto.php';

function asr_av_can(string $perm): bool {
    return function_exists('asr_user_has_permission') ? asr_user_has_permission('access_vault.' . $perm) : isAdmin();
}

function asr_av_require(string $perm): void {
    if (!asr_av_can($perm)) {
        throw new RuntimeException('Недостаточно прав для этого действия.');
    }
}

function asr_av_redirect(string $category = 'sites', string $msg = '', string $error = ''): void {
    $url = 'admin.php?tab=access_vault&category=' . urlencode(asr_av_normalize_category($category));
    if ($msg !== '') $url .= '&av_msg=' . urlencode($msg);
    if ($error !== '') $url .= '&av_error=' . urlencode($error);
    header('Location: ' . $url);
    exit;
}

function asr_av_normalize_text(string $value, int $max = 255, bool $required = false, string $label = 'Поле'): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if ($required && $value === '') throw new RuntimeException($label . ' обязательно.');
    if (mb_strlen($value, 'UTF-8') > $max) throw new RuntimeException($label . ' слишком длинное.');
    return $value;
}

function asr_av_normalize_comment(string $value): string {
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > 5000) throw new RuntimeException('Комментарий слишком длинный.');
    return $value;
}

function asr_av_normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url) && !filter_var($url, FILTER_VALIDATE_EMAIL)) {
        // Для почты иногда нужен просто адрес, для остальных разделов лучше URL. Не душим пользователя.
        if (!preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $url)) {
            throw new RuntimeException('URL должен начинаться с http:// или https:// либо быть email-адресом.');
        }
    }
    if (mb_strlen($url, 'UTF-8') > 500) throw new RuntimeException('URL слишком длинный.');
    return $url;
}

function asr_av_generate_password(int $length = 20): string {
    $length = max(8, min(64, $length));
    $sets = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnopqrstuvwxyz',
        '23456789',
        '!@#$%^&*()-_=+[]{};:,.?',
    ];
    $chars = implode('', $sets);
    $password = '';
    foreach ($sets as $set) {
        $password .= $set[random_int(0, strlen($set) - 1)];
    }
    while (strlen($password) < $length) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $arr = str_split($password);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
    return implode('', $arr);
}

function asr_av_format_credential_text(array $resource, array $credential, string $plainPassword): string {
    $lines = [];
    $lines[] = 'Название: ' . (string)($resource['title'] ?? $credential['resource_title'] ?? '');
    $url = (string)($resource['url'] ?? $credential['resource_url'] ?? '');
    if ($url !== '') $lines[] = 'URL: ' . $url;
    $lines[] = 'Логин: ' . (string)($credential['login'] ?? '');
    $lines[] = 'Пароль: ' . $plainPassword;
    $comment = trim((string)($credential['comment'] ?? ''));
    if ($comment !== '') $lines[] = 'Комментарий: ' . $comment;
    return implode("\n", $lines);
}

function asr_av_decrypted_credential_text(PDO $pdo, int $credentialId): string {
    $credential = asr_av_find_credential($pdo, $credentialId);
    if (!$credential) throw new RuntimeException('Доступ не найден.');
    $resource = asr_av_find_resource($pdo, (int)$credential['resource_id']) ?: [];
    $plain = asr_access_vault_decrypt((string)$credential['password_encrypted']);
    return asr_av_format_credential_text($resource, $credential, $plain);
}

function asr_av_send_credential_email(PDO $pdo, int $credentialId, int $toUserId, string $note = ''): void {
    asr_av_require('share');
    $recipient = null;
    foreach (asr_av_get_users_for_share($pdo) as $u) {
        if ((int)$u['id'] === $toUserId) { $recipient = $u; break; }
    }
    if (!$recipient) throw new RuntimeException('Получатель не найден.');
    $to = trim((string)$recipient['username']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('У выбранного пользователя логин не похож на email.');

    $credential = asr_av_find_credential($pdo, $credentialId);
    if (!$credential) throw new RuntimeException('Доступ не найден.');
    $text = asr_av_decrypted_credential_text($pdo, $credentialId);
    $sender = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Администратор'));
    $body = "Вам отправлены доступы.\n\n" . $text . "\n\nОтправил: " . $sender;
    if (trim($note) !== '') $body .= "\nКомментарий отправителя: " . trim($note);
    $subject = 'Доступ к ресурсу: ' . (string)($credential['resource_title'] ?? '');
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    $config = function_exists('asr_smtp_config_from_settings') ? asr_smtp_config_from_settings($settings) : [];
    if (!function_exists('asr_send_smtp_mail') || !asr_send_smtp_mail($to, $subject, $body, $config)) {
        throw new RuntimeException('Не удалось отправить письмо через SMTP. Проверьте почтовые настройки.');
    }
    asr_av_audit($pdo, 'credential_shared_email', (int)$credential['resource_id'], $credentialId, ['to_user_id' => $toUserId, 'to' => $to]);
}

function asr_av_csv_escape($v): string {
    return (string)$v;
}

function asr_av_export_csv(PDO $pdo, string $category): void {
    asr_av_require('import_export');
    $resources = asr_av_resources($pdo, $category, '');
    $resourceIds = array_map(fn($r) => (int)$r['id'], $resources);
    $creds = asr_av_credentials_for_resources($pdo, $resourceIds, true);
    $filename = 'access_vault_' . $category . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['category','group','title','url','resource_comment','login','password','credential_comment','status'], ';');
    foreach ($resources as $r) {
        foreach (($creds[(int)$r['id']] ?? [[]]) as $c) {
            $pass = $c ? asr_access_vault_decrypt((string)$c['password_encrypted']) : '';
            fputcsv($out, [$r['category'], $r['group_title'] ?: '', $r['title'], $r['url'], $r['comment'], $c['login'] ?? '', $pass, $c['comment'] ?? '', $r['status']], ';');
        }
    }
    asr_av_audit($pdo, 'resource_exported', null, null, ['category' => $category, 'count' => count($resources)]);
    exit;
}


/* -------------------------------------------------------------------------
 * Уведомления об оплате ресурсов: Telegram + email.
 * ------------------------------------------------------------------------- */
function asr_av_payment_default_message(array $resource = []): string {
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    $template = trim((string)($settings['access_vault_payment_notification_message'] ?? ''));
    if ($template !== '') return $template;
    return "Здравствуйте, {{name_user}}!\n\nНужно оплатить ресурс.\nСумма: {{pay}}\nДата оплаты: {{date_pay}}\nПериод: {{period}}";
}


function asr_av_payment_month_name(int $month): string {
    $months = [1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'];
    return $months[$month] ?? '';
}

function asr_av_payment_date_label(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
    try {
        $dt = new DateTimeImmutable($date . ' 00:00:00');
        return (int)$dt->format('j') . ' ' . asr_av_payment_month_name((int)$dt->format('n'));
    } catch (Throwable $e) { return $date; }
}

function asr_av_payment_amount_label(array $payment): string {
    $amount = trim((string)($payment['payment_amount'] ?? ''));
    $currency = trim((string)($payment['payment_currency'] ?? '')) ?: '₸';
    if ($amount === '') return 'не указан';
    $normalized = str_replace(',', '.', $amount);
    if (is_numeric($normalized)) {
        $formatted = number_format((float)$normalized, 2, ',', ' ');
        $formatted = preg_replace('/,00$/', '', $formatted);
        $formatted = preg_replace('/(,\d)0$/', '$1', $formatted);
        return trim($formatted . ' ' . $currency);
    }
    return trim($amount . ' ' . $currency);
}

function asr_av_payment_period_label(array $payment): string {
    $period = (string)($payment['auto_payment_period'] ?? '');
    if ($period === '') $period = (string)($payment['repeat_type'] ?? '');
    if ($period === 'monthly') return 'ежемесячно';
    if ($period === 'yearly') return 'ежегодно';
    return 'разово';
}

function asr_av_payment_user_name(array $user = []): string {
    $name = trim((string)($user['full_name'] ?? ''));
    if ($name !== '') return $name;
    $username = trim((string)($user['username'] ?? ''));
    return $username !== '' ? $username : 'сотрудник';
}

function asr_av_render_payment_template(string $template, array $payment, array $user = []): string {
    return strtr($template, [
        '{{name_user}}' => asr_av_payment_user_name($user),
        '{{pay}}' => asr_av_payment_amount_label($payment),
        '{{date_pay}}' => asr_av_payment_date_label((string)($payment['payment_date'] ?? '')),
        '{{period}}' => asr_av_payment_period_label($payment),
    ]);
}

function asr_av_telegram_config(): array {
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    return [
        'bot_token' => trim((string)($settings['telegram_bot_token'] ?? '')),
        'webhook_secret' => trim((string)($settings['telegram_webhook_secret'] ?? '')),
        'bot_username' => trim((string)($settings['telegram_bot_username'] ?? '')),
    ];
}

function asr_av_telegram_api(string $method, array $payload): array {
    $cfg = asr_av_telegram_config();
    if ($cfg['bot_token'] === '') throw new RuntimeException('Не указан токен Telegram-бота в настройках.');
    $ch = curl_init('https://api.telegram.org/bot' . $cfg['bot_token'] . '/' . $method);
    if (!$ch) throw new RuntimeException('Не удалось инициализировать curl.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException('Telegram API error: ' . ($err ?: $raw ?: 'HTTP ' . $code));
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || empty($data['ok'])) throw new RuntimeException('Telegram API вернул ошибку: ' . (string)$raw);
    return $data;
}

function asr_av_send_telegram_message(string $chatId, string $text): void {
    asr_av_telegram_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}


function asr_av_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asr_av_access_event_type(string $action): ?string {
    if (in_array($action, ['resource_created', 'credential_created', 'group_created', 'resources_imported'], true)) return 'create';
    if (in_array($action, ['resource_updated', 'credential_updated', 'group_updated', 'credential_reordered'], true)) return 'update';
    if (in_array($action, ['resource_archived', 'credential_archived', 'group_deleted', 'resource_deleted', 'credential_deleted', 'resource_deleted_permanent', 'credential_deleted_permanent'], true)) return 'delete';
    return null;
}

function asr_av_access_event_notifications_enabled(string $eventType): bool {
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    if ((string)($settings['access_vault_tg_notifications_disabled'] ?? '0') === '1') return false;
    $key = [
        'create' => 'access_vault_tg_notify_create',
        'delete' => 'access_vault_tg_notify_delete',
        'update' => 'access_vault_tg_notify_update',
    ][$eventType] ?? '';
    return $key !== '' && (string)($settings[$key] ?? '1') === '1';
}


function asr_av_category_label(string $category): string {
    $labels = [
        'sites' => 'Наши сайты',
        'services' => 'Сервисы',
        'social' => 'Соц. сети',
        'email' => 'Почта',
        'archive' => 'Архив',
        'audit' => 'Журнал',
        'import_export' => 'Импорт/экспорт',
    ];
    return $labels[$category] ?? $category;
}

function asr_av_access_event_action_label(string $action): string {
    $labels = [
        'resource_created' => 'Ресурс добавлен',
        'resource_updated' => 'Ресурс изменён',
        'resource_archived' => 'Ресурс отправлен в архив',
        'resource_restored' => 'Ресурс восстановлен',
        'credential_created' => 'Доступ добавлен',
        'credential_updated' => 'Доступ изменён',
        'credential_archived' => 'Доступ отправлен в архив',
        'credential_restored' => 'Доступ восстановлен',
        'group_created' => 'Группа добавлена',
        'group_updated' => 'Группа изменена',
        'group_deleted' => 'Группа удалена',
        'resources_imported' => 'Импортированы доступы',
    ];
    return $labels[$action] ?? $action;
}

function asr_av_access_event_recipients(PDO $pdo): array {
    if (!asr_table_column_exists($pdo, 'oca_users', 'telegram_chat_id')) return [];
    $activeWhere = asr_table_column_exists($pdo, 'oca_users', 'is_active') ? ' AND is_active = 1' : '';
    $sql = "SELECT id, full_name, username, telegram_chat_id FROM oca_users WHERE COALESCE(telegram_chat_id, '') <> '' AND role IN ('superadmin','admin'){$activeWhere} ORDER BY id ASC";
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function asr_av_access_event_context(PDO $pdo, ?int $resourceId, ?int $credentialId): array {
    $resource = [];
    $credential = [];
    try {
        if ($resourceId) {
            $stmt = $pdo->prepare('SELECT id, title, url, category FROM oca_access_resources WHERE id = ? LIMIT 1');
            $stmt->execute([$resourceId]);
            $resource = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        if ($credentialId) {
            $stmt = $pdo->prepare('SELECT id, login, resource_id FROM oca_access_credentials WHERE id = ? LIMIT 1');
            $stmt->execute([$credentialId]);
            $credential = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$resource && !empty($credential['resource_id'])) {
                $stmt = $pdo->prepare('SELECT id, title, url, category FROM oca_access_resources WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$credential['resource_id']]);
                $resource = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (Throwable $e) {}
    return [$resource, $credential];
}

function asr_av_access_event_telegram_notify(PDO $pdo, string $action, ?int $resourceId = null, ?int $credentialId = null, array $details = [], ?int $actorId = null): void {
    $eventType = asr_av_access_event_type($action);
    if ($eventType === null || !asr_av_access_event_notifications_enabled($eventType)) return;

    $recipients = asr_av_access_event_recipients($pdo);
    if (!$recipients) return;

    [$resource, $credential] = asr_av_access_event_context($pdo, $resourceId, $credentialId);
    $actor = 'Пользователь #' . (int)$actorId;
    if ($actorId) {
        try {
            $stmt = $pdo->prepare('SELECT full_name, username FROM oca_users WHERE id = ? LIMIT 1');
            $stmt->execute([$actorId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $actor = trim((string)($u['full_name'] ?? '')) ?: (trim((string)($u['username'] ?? '')) ?: $actor);
        } catch (Throwable $e) {}
    }

    $category = (string)($resource['category'] ?? ($details['category'] ?? ''));
    $categoryLabel = $category !== '' ? asr_av_category_label($category) : '—';
    $resourceTitle = trim((string)($resource['title'] ?? ($details['title'] ?? '')));
    $credentialLogin = trim((string)($credential['login'] ?? ($details['login'] ?? '')));

    $lines = [];
    $lines[] = '🔐 <b>' . asr_av_html(asr_av_access_event_action_label($action)) . '</b>';
    $lines[] = 'Кто: ' . asr_av_html($actor);
    if ($categoryLabel !== '—') $lines[] = 'Раздел: ' . asr_av_html($categoryLabel);
    if ($resourceTitle !== '') $lines[] = 'Ресурс: ' . asr_av_html($resourceTitle);
    if ($credentialLogin !== '') $lines[] = 'Доступ: ' . asr_av_html($credentialLogin);
    if (!empty($resource['url'])) $lines[] = 'Ссылка: ' . asr_av_html((string)$resource['url']);

    foreach ($recipients as $recipient) {
        $chatId = trim((string)($recipient['telegram_chat_id'] ?? ''));
        if ($chatId === '') continue;
        try { asr_av_send_telegram_message($chatId, implode("
", $lines)); } catch (Throwable $e) {}
    }
}

function asr_av_payment_message(array $payment, int $daysBefore, array $user = []): string {
    $title = (string)($payment['resource_title'] ?? '');
    $url = trim((string)($payment['resource_url'] ?? ''));
    $date = asr_av_payment_date_label((string)($payment['payment_date'] ?? ''));
    $custom = trim((string)($payment['message'] ?? ''));
    if ($custom === '') $custom = asr_av_payment_default_message($payment);
    $custom = asr_av_render_payment_template($custom, $payment, $user);
    $lines = [];
    $lines[] = '🔔 <b>Напоминание об оплате</b>';
    $lines[] = '';
    $lines[] = '<b>Ресурс:</b> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    if ($url !== '') $lines[] = '<b>URL:</b> ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $lines[] = '<b>Дата оплаты:</b> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    $lines[] = '<b>Осталось дней:</b> ' . (int)$daysBefore;
    if ($custom !== '') { $lines[] = ''; $lines[] = htmlspecialchars($custom, ENT_QUOTES, 'UTF-8'); }
    return implode("\n", $lines);
}

function asr_av_payment_email_message(array $payment, int $daysBefore, array $user = []): string {
    $title = (string)($payment['resource_title'] ?? '');
    $url = trim((string)($payment['resource_url'] ?? ''));
    $date = asr_av_payment_date_label((string)($payment['payment_date'] ?? ''));
    $custom = trim((string)($payment['message'] ?? ''));
    if ($custom === '') $custom = asr_av_payment_default_message($payment);
    $custom = asr_av_render_payment_template($custom, $payment, $user);
    $lines = [];
    $lines[] = 'Напоминание об оплате';
    $lines[] = '';
    $lines[] = 'Ресурс: ' . $title;
    if ($url !== '') $lines[] = 'URL: ' . $url;
    $lines[] = 'Дата оплаты: ' . $date;
    $lines[] = 'Осталось дней: ' . (int)$daysBefore;
    if ($custom !== '') { $lines[] = ''; $lines[] = $custom; }
    return implode("\n", $lines);
}

function asr_av_send_payment_email(array $user, array $payment, int $daysBefore): void {
    $to = trim((string)($user['username'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('У пользователя не указан email-логин.');
    $settings = function_exists('asr_get_all_settings') ? asr_get_all_settings() : [];
    $config = function_exists('asr_smtp_config_from_settings') ? asr_smtp_config_from_settings($settings) : [];
    $subject = 'Напоминание об оплате: ' . (string)($payment['resource_title'] ?? 'ресурс');
    if (!function_exists('asr_send_smtp_mail') || !asr_send_smtp_mail($to, $subject, asr_av_payment_email_message($payment, $daysBefore, $user), $config)) {
        throw new RuntimeException('Не удалось отправить email-напоминание через SMTP.');
    }
}

function asr_av_run_payment_reminders(PDO $pdo): array {
    if (!asr_av_payment_tables_ready($pdo)) return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => ['Таблицы напоминаний не найдены.']];
    $due = asr_av_due_payment_notifications($pdo, new DateTimeImmutable('now'));
    $sent = 0; $failed = 0; $skipped = 0; $errors = [];
    $users = [];
    foreach (asr_av_users_for_payment($pdo) as $u) $users[(int)$u['id']] = $u;
    foreach ($due as $item) {
        $payment = $item['payment'];
        $paymentId = (int)$payment['id'];
        $userId = (int)$item['user_id'];
        $daysBefore = (int)$item['days_before'];
        $remindDate = (string)$item['remind_date'];
        if (asr_av_notification_already_sent($pdo, $paymentId, $userId, $remindDate)) { $skipped++; continue; }
        $user = $users[$userId] ?? null;
        if (!$user) {
            asr_av_record_payment_notification($pdo, $paymentId, $userId, $remindDate, 'failed', 'Пользователь-получатель не найден или Telegram не подключён.');
            $failed++; continue;
        }
        $ok = []; $warn = [];
        try { asr_av_send_telegram_message((string)$user['telegram_chat_id'], asr_av_payment_message($payment, $daysBefore, $user)); $ok[] = 'telegram'; } catch (Throwable $e) { $warn[] = 'TG: ' . $e->getMessage(); }
        try { asr_av_send_payment_email($user, $payment, $daysBefore); $ok[] = 'email'; } catch (Throwable $e) { $warn[] = 'Email: ' . $e->getMessage(); }
        if ($ok) {
            asr_av_record_payment_notification($pdo, $paymentId, $userId, $remindDate, 'sent', implode('; ', $warn));
            asr_av_audit($pdo, 'payment_reminder_sent', (int)$payment['resource_id'], null, ['payment_id' => $paymentId, 'to_user_id' => $userId, 'channels' => $ok, 'warnings' => $warn]);
            $sent++;
        } else {
            $err = implode('; ', $warn) ?: 'Не удалось отправить уведомление.';
            asr_av_record_payment_notification($pdo, $paymentId, $userId, $remindDate, 'failed', $err);
            $errors[] = $err;
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 5)];
}


function asr_av_user_telegram_column_exists(PDO $pdo, string $column): bool {
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `oca_users` LIKE ?');
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function asr_av_ensure_user_telegram_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute(['oca_users']);
        if (!$stmt->fetchColumn()) return;
    } catch (Throwable $e) {
        return;
    }

    $columns = [
        'telegram_chat_id' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_chat_id` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_username' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_username` VARCHAR(128) NULL DEFAULT NULL",
        'telegram_bind_token' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL",
        'telegram_bound_at' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bound_at` DATETIME NULL DEFAULT NULL",
    ];

    foreach ($columns as $column => $sql) {
        if (!asr_av_user_telegram_column_exists($pdo, $column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* нет ALTER-прав — не роняем страницу */ }
        }
    }
}

function asr_av_ensure_user_telegram_token(PDO $pdo, int $userId): string {
    if ($userId <= 0) return '';
    asr_av_ensure_user_telegram_schema($pdo);
    if (!asr_av_user_telegram_column_exists($pdo, 'telegram_bind_token')) return '';

    try {
        $stmt = $pdo->prepare('SELECT telegram_bind_token FROM oca_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $token = trim((string)$stmt->fetchColumn());
        if ($token !== '') return $token;

        $token = bin2hex(random_bytes(16));
        $upd = $pdo->prepare('UPDATE oca_users SET telegram_bind_token = ? WHERE id = ?');
        $upd->execute([$token, $userId]);
        return $token;
    } catch (Throwable $e) {
        return '';
    }
}

function asr_av_user_telegram_link(PDO $pdo, int $userId): string {
    $cfg = asr_av_telegram_config();
    if ($cfg['bot_username'] === '') return '';
    $token = asr_av_ensure_user_telegram_token($pdo, $userId);
    if ($token === '') return '';
    return 'https://t.me/' . ltrim($cfg['bot_username'], '@') . '?start=u' . $userId . '_' . $token;
}
