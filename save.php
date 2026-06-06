<?php
/**
 * Финальное сохранение результатов Оксфордского теста анализа личности.
 * Если тест был начат через save_progress.php, обновляет существующий черновик.
 * Если старый фронт отправил сразу все ответы без черновика, создаёт новую завершённую запись.
 */

header('Content-Type: application/json; charset=utf-8');

// --- ПОДКЛЮЧЕНИЕ К БД И НАСТРОЙКАМ ---
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_app/lib/settings.php';
require_once __DIR__ . '/admin_app/lib/anti_spam.php';

$settings = asr_get_all_settings();
$smtp_config = [
    'host'      => $settings['mail_host'],
    'port'      => (int)$settings['mail_port'],
    'secure'    => $settings['mail_secure'],
    'username'  => $settings['mail_username'],
    'password'  => $settings['mail_password'],
    'from_name' => $settings['mail_from_name']
];
$admin_emails = asr_normalize_emails($settings['notification_emails']);

function asr_base_url_for_save(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return function_exists('asr_config_app_base_url') ? asr_config_app_base_url() : ($scheme . '://' . 'localhost' . '/');
}

function encodeIdForSave($id) {
    $salt = 'OcaAvmSecureSalt2026!';
    $data = $id . '-' . substr(md5($id . $salt), 0, 12);
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function normalizeAnswerForCalc($answer) {
    if ($answer === '+') return 1;
    if ($answer === '?') return 2;
    if ($answer === '-') return 3;
    return 2;
}

function normalizeGenderForCalc($gender) {
    return ($gender === 'Женский') ? 2 : 1;
}

function normalizeAgeForCalc($age) {
    return ($age === 'Младше 18 лет') ? 2 : 1;
}

function asr_clean_save_string($value, int $max = 255): string {
    $value = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function asr_fail_save(string $message, int $code = 400, ?string $debug = null): void {
    http_response_code($code);
    $payload = ["status" => "error", "message" => $message];
    if ($debug !== null && defined('ASR_DEBUG') && ASR_DEBUG) {
        $payload['debug'] = $debug;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    asr_fail_save('Метод не поддерживается', 405);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    asr_fail_save('Некорректный JSON');
}

$user = $input['user'] ?? [];
$answers = $input['answers'] ?? [];
$testId = (int)($input['test_id'] ?? $input['id'] ?? 0);
$resumeToken = asr_clean_save_string($input['token'] ?? '', 64);

if (!is_array($answers) || count($answers) < 200) {
    asr_fail_save('Не получены все ответы теста');
}

$answers = array_slice($answers, 0, 200);
foreach ($answers as $answer) {
    if (!in_array($answer, ['+', '?', '-'], true)) {
        asr_fail_save('Не получены все ответы теста');
    }
}

$antispam = asr_check_start_user(is_array($user) ? $user : []);
if (!$antispam['ok']) {
    try { asr_insert_blocked_start($pdo, $antispam['user'] ?? [], $antispam['reason'] ?? 'blocked'); } catch (Throwable $e) {}
    asr_fail_save($antispam['message'] ?? 'Не удалось сохранить тест. Проверьте корректность введённых данных.', 422);
}
$validatedUser = $antispam['user'];
asr_ensure_result_security_columns($pdo);

$name   = $validatedUser['name'];
$email  = $validatedUser['email'];
$phone  = $validatedUser['phone'];
$phoneNormalized = $validatedUser['phone_normalized'] ?? asr_normalize_phone($phone);
$city   = $validatedUser['city'];
$gender = $validatedUser['gender'];
$age    = $validatedUser['age'];
$role   = $validatedUser['role'];
$utm    = $validatedUser['utm'];

$sexCalc = normalizeGenderForCalc($gender);
$ageCalc = normalizeAgeForCalc($age);

$calcInput = [];
$calcInput[0] = $sexCalc;
$calcInput[1] = $ageCalc;

for ($i = 0; $i < 200; $i++) {
    $calcInput[$i + 2] = normalizeAnswerForCalc($answers[$i]);
}

require_once __DIR__ . '/osaPHPavs.php';
$graph_scores = OcaCalk($calcInput);
$base_url = asr_base_url_for_save();

try {
    $last_id = 0;
    $alreadyCompleted = false;

    if ($testId > 0 && $resumeToken !== '') {
        $checkStmt = $pdo->prepare("SELECT * FROM oca_results WHERE id = ? AND resume_token = ? LIMIT 1");
        $checkStmt->execute([$testId, $resumeToken]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            asr_fail_save('Черновик теста не найден', 404);
        }

        $alreadyCompleted = (($existing['status'] ?? '') === 'completed');
        $last_id = (int)$existing['id'];

        $update = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'city' => $city,
            'gender' => $gender,
            'age' => $age,
            'role' => $role,
            'utm' => $utm,
            'graph_scores' => $graph_scores,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'status' => 'completed',
            'current_page' => 10,
        ];
        if (asr_column_exists_safe($pdo, 'oca_results', 'completed_at')) $update['completed_at'] = date('Y-m-d H:i:s');
        if (asr_column_exists_safe($pdo, 'oca_results', 'phone_normalized')) $update['phone_normalized'] = $phoneNormalized;
        if (asr_column_exists_safe($pdo, 'oca_results', 'spam_status')) $update['spam_status'] = 'ok';
        if (asr_column_exists_safe($pdo, 'oca_results', 'spam_reason')) $update['spam_reason'] = '';

        $sets = [];
        $values = [];
        foreach ($update as $col => $val) {
            $sets[] = '`' . str_replace('`', '``', $col) . '` = ?';
            $values[] = $val;
        }
        $values[] = $last_id;
        $values[] = $resumeToken;
        $stmt = $pdo->prepare('UPDATE oca_results SET ' . implode(', ', $sets) . ' WHERE id = ? AND resume_token = ?');
        $stmt->execute($values);
    } else {
        $resumeToken = bin2hex(random_bytes(24));
        $insert = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'city' => $city,
            'gender' => $gender,
            'age' => $age,
            'role' => $role,
            'utm' => $utm,
            'graph_scores' => $graph_scores,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'status' => 'completed',
            'current_page' => 10,
            'resume_token' => $resumeToken,
            'created_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s'),
        ];
        foreach ([
            'phone_normalized' => $phoneNormalized,
            'spam_status' => 'ok',
            'spam_reason' => '',
            'spam_ip' => asr_client_ip(),
        ] as $col => $val) {
            if (asr_column_exists_safe($pdo, 'oca_results', $col)) {
                $insert[$col] = $val;
            }
        }
        $cols = array_keys($insert);
        $stmt = $pdo->prepare('INSERT INTO oca_results (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $stmt->execute(array_values($insert));

        $last_id = (int)$pdo->lastInsertId();
    }

    $b24_result = false;

    // Bitrix24 и письмо отправляем только один раз — при первом финальном завершении.
    if (!$alreadyCompleted) {
        $integration_path = __DIR__ . '/b24_integration/sync_test.php';
        if (file_exists($integration_path)) {
            require_once $integration_path;

            if (function_exists('sendTestToBitrix24')) {
                $b24_result = sendTestToBitrix24([
                    'test_id'      => $last_id,
                    'name'         => $name,
                    'phone'        => $phone,
                    'email'        => $email,
                    'city'         => $city,
                    'gender'       => $gender,
                    'age'          => $age,
                    'role'         => $role,
                    'utm'          => $utm,
                    'manager_link' => $base_url . 'admin.php?view=' . $last_id,
                    'client_link'  => $base_url . 'admin.php?shared=' . encodeIdForSave($last_id),
                    'deal_url'     => $existing['crm_deal'] ?? '',
                    'deal_id'      => $existing['crm_deal_id'] ?? 0,
                    'contact_id'   => $existing['crm_contact_id'] ?? 0,
                    'phone_normalized' => $phoneNormalized
                ]);

                if ($b24_result && !empty($b24_result['deal_url'])) {
                    $update_sql = "UPDATE oca_results SET crm_deal = ?, crm_contact = ?, crm_deal_id = ?, crm_contact_id = ?, crm_sync_status = 'success', crm_sync_error = NULL, crm_last_sync_at = NOW() WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([
                        $b24_result['deal_url'],
                        $b24_result['contact_url'] ?? '',
                        $b24_result['deal_id'] ?? null,
                        $b24_result['contact_id'] ?? null,
                        $last_id
                    ]);
                } elseif ($b24_result === false && asr_column_exists_safe($pdo, 'oca_results', 'crm_sync_status')) {
                    $update_stmt = $pdo->prepare("UPDATE oca_results SET crm_sync_status = 'error', crm_sync_error = ?, crm_last_sync_at = NOW() WHERE id = ?");
                    $update_stmt->execute(['Bitrix24 не вернул успешный ответ', $last_id]);
                }
            }
        }

        $view_url = $base_url . "admin.php?view=" . $last_id;
        $client_url = $base_url . "admin.php?shared=" . encodeIdForSave($last_id);
        $bitrixDealBlock = '';
        if ($b24_result && !empty($b24_result['deal_url'])) {
            $bitrixDealBlock = "Сделка в Битрикс24:\n" . $b24_result['deal_url'] . "\n";
        }
        $tplVars = [
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'city' => $city,
            'role' => $role,
            'date' => date("d.m.Y H:i"),
            'manager_link' => $view_url,
            'client_link' => $client_url,
            'bitrix_deal_block' => $bitrixDealBlock,
        ];
        $subject = asr_render_template($settings['notification_subject'], $tplVars);
        $message = asr_render_template($settings['notification_body'], $tplVars);

        $mail_sent = false;
        foreach ($admin_emails as $admin_email) {
            $mail_sent = smtp_mail($admin_email, $subject, $message, $smtp_config) || $mail_sent;
        }
    } else {
        $mail_sent = false;
    }

    echo json_encode([
        "status" => "success",
        "id" => $last_id,
        "graph_scores" => $graph_scores,
        "mail" => $mail_sent,
        "bitrix24" => $b24_result,
        "already_completed" => $alreadyCompleted
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    asr_fail_save('Ошибка сохранения результата', 500, $e->getMessage());
}

/**
 * Функция отправки почты через SMTP (сокеты)
 */
function smtp_mail($to, $subject, $message, $config) {
    $domain = parse_url('https://' . $config['host'], PHP_URL_HOST);
    $message_id = "<" . md5(uniqid(time())) . "@" . $domain . ">";

    $header  = "To: $to\r\n";
    $header .= "From: =?UTF-8?B?" . base64_encode($config['from_name']) . "?= <{$config['username']}>\r\n";
    $header .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $header .= "Date: " . date('r') . "\r\n";
    $header .= "Message-ID: $message_id\r\n";
    $header .= "MIME-Version: 1.0\r\n";
    $header .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $header .= "Content-Transfer-Encoding: 8bit\r\n";

    $host = ($config['secure'] == 'ssl') ? 'ssl://' . $config['host'] : $config['host'];
    $socket = @fsockopen($host, $config['port'], $errno, $errstr, 15);

    if (!$socket) return false;

    function smtp_res($socket) {
        $data = "";
        while ($str = fgets($socket, 512)) {
            $data .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $data;
    }

    smtp_res($socket);
    fputs($socket, "EHLO $domain\r\n");
    smtp_res($socket);
    fputs($socket, "AUTH LOGIN\r\n");
    smtp_res($socket);
    fputs($socket, base64_encode($config['username']) . "\r\n");
    smtp_res($socket);
    fputs($socket, base64_encode($config['password']) . "\r\n");
    smtp_res($socket);
    fputs($socket, "MAIL FROM: <{$config['username']}>\r\n");
    smtp_res($socket);
    fputs($socket, "RCPT TO: <$to>\r\n");
    smtp_res($socket);
    fputs($socket, "DATA\r\n");
    smtp_res($socket);
    fputs($socket, $header . "\r\n" . $message . "\r\n.\r\n");
    smtp_res($socket);
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}