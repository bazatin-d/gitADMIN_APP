<?php
/**
 * Промежуточное сохранение OCA/АСР теста.
 * Создаёт черновик после заполнения вводной формы и обновляет ответы после каждой страницы.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_app/lib/anti_spam.php';

function asr_json_response(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function asr_clean_string($value, int $max = 255): string {
    $value = trim((string)$value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function asr_normalize_answers($answers): array {
    $result = array_fill(0, 200, null);
    if (!is_array($answers)) {
        return $result;
    }

    for ($i = 0; $i < 200; $i++) {
        $answer = $answers[$i] ?? null;
        $result[$i] = in_array($answer, ['+', '?', '-'], true) ? $answer : null;
    }

    return $result;
}

function asr_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    return function_exists('asr_config_app_base_url') ? asr_config_app_base_url() : ($scheme . '://' . 'localhost' . '/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    asr_json_response(['status' => 'error', 'message' => 'Метод не поддерживается'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    asr_json_response(['status' => 'error', 'message' => 'Некорректный JSON'], 400);
}

$action = $input['action'] ?? '';

try {
    if ($action === 'start') {
        $user = $input['user'] ?? [];
        if (!is_array($user)) {
            asr_json_response(['status' => 'error', 'message' => 'Некорректные данные пользователя'], 400);
        }

        asr_ensure_result_security_columns($pdo);
        $antispam = asr_check_start_user($user);
        if (!$antispam['ok']) {
            asr_insert_blocked_start($pdo, $antispam['user'] ?? [], $antispam['reason'] ?? 'blocked');
            asr_json_response(['status' => 'error', 'message' => $antispam['message'] ?? 'Не удалось начать тест. Проверьте корректность введённых данных и попробуйте ещё раз.'], 422);
        }

        $validatedUser = $antispam['user'];

        $name   = $validatedUser['name'];
        $email  = $validatedUser['email'];
        $phone  = $validatedUser['phone'];
        $phoneNormalized = $validatedUser['phone_normalized'] ?? asr_normalize_phone($phone);
        $city   = $validatedUser['city'];
        $gender = $validatedUser['gender'];
        $age    = $validatedUser['age'];
        $role   = $validatedUser['role'];
        $utm    = $validatedUser['utm'];

        $token = bin2hex(random_bytes(24));
        $answers = array_fill(0, 200, null);

        $insert = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'city' => $city,
            'gender' => $gender,
            'age' => $age,
            'role' => $role,
            'utm' => $utm,
            'graph_scores' => '',
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'status' => 'in_progress',
            'current_page' => 1,
            'resume_token' => $token,
            'created_at' => date('Y-m-d H:i:s'),
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

        $id = (int)$pdo->lastInsertId();
        asr_json_response([
            'status' => 'success',
            'id' => $id,
            'token' => $token,
            'resume_url' => asr_base_url() . '?resume=' . urlencode($token)
        ]);
    }

    if ($action === 'page') {
        $id = (int)($input['test_id'] ?? 0);
        $token = asr_clean_string($input['token'] ?? '', 64);
        $currentPage = max(1, min(10, (int)($input['current_page'] ?? 1)));
        $answers = asr_normalize_answers($input['answers'] ?? []);

        if ($id <= 0 || $token === '') {
            asr_json_response(['status' => 'error', 'message' => 'Нет идентификатора черновика'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM oca_results WHERE id = ? AND resume_token = ? LIMIT 1");
        $stmt->execute([$id, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            asr_json_response(['status' => 'error', 'message' => 'Черновик теста не найден'], 404);
        }
        if (($row['status'] ?? '') === 'completed') {
            asr_json_response(['status' => 'success', 'message' => 'Тест уже завершён']);
        }

        $answeredCount = count(array_filter($answers, static fn($v) => in_array($v, ['+', '?', '-'], true)));

        $stmt = $pdo->prepare("UPDATE oca_results
            SET answers = ?, current_page = ?, status = 'in_progress'
            WHERE id = ? AND resume_token = ?");
        $stmt->execute([
            json_encode($answers, JSON_UNESCAPED_UNICODE),
            $currentPage,
            $id,
            $token
        ]);

        $b24Partial = null;
        if ($answeredCount >= 20 && empty($row['crm_deal']) && empty($row['crm_deal_id']) && (($row['spam_status'] ?? 'ok') !== 'blocked')) {
            $integrationPath = __DIR__ . '/b24_integration/sync_test.php';
            if (file_exists($integrationPath)) {
                require_once $integrationPath;
                if (function_exists('sendPartialTestToBitrix24')) {
                    $resumeUrl = asr_base_url() . '?resume=' . urlencode($token);
                    $b24Partial = sendPartialTestToBitrix24([
                        'test_id' => $id,
                        'name' => $row['name'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'phone_normalized' => $row['phone_normalized'] ?? '',
                        'email' => $row['email'] ?? '',
                        'city' => $row['city'] ?? '',
                        'gender' => $row['gender'] ?? '',
                        'age' => $row['age'] ?? '',
                        'role' => $row['role'] ?? '',
                        'utm' => $row['utm'] ?? '',
                        'resume_link' => $resumeUrl,
                        'deal_url' => $row['crm_deal'] ?? '',
                        'deal_id' => $row['crm_deal_id'] ?? 0,
                        'contact_id' => $row['crm_contact_id'] ?? 0,
                    ]);
                    if ($b24Partial && !empty($b24Partial['deal_url'])) {
                        $update = [];
                        $values = [];
                        foreach ([
                            'crm_deal' => $b24Partial['deal_url'],
                            'crm_contact' => $b24Partial['contact_url'] ?? '',
                            'crm_deal_id' => $b24Partial['deal_id'] ?? null,
                            'crm_contact_id' => $b24Partial['contact_id'] ?? null,
                            'crm_sync_status' => 'partial_success',
                            'crm_sync_error' => null,
                            'crm_last_sync_at' => date('Y-m-d H:i:s'),
                        ] as $col => $val) {
                            if (asr_column_exists_safe($pdo, 'oca_results', $col)) { $update[] = '`' . $col . '` = ?'; $values[] = $val; }
                        }
                        if ($update) {
                            $values[] = $id;
                            $pdo->prepare('UPDATE oca_results SET ' . implode(', ', $update) . ' WHERE id = ?')->execute($values);
                        }
                    } elseif (asr_column_exists_safe($pdo, 'oca_results', 'crm_sync_status')) {
                        $pdo->prepare("UPDATE oca_results SET crm_sync_status = 'partial_error', crm_sync_error = ?, crm_last_sync_at = NOW() WHERE id = ?")->execute(['Bitrix24 не вернул успешный ответ на промежуточном этапе', $id]);
                    }
                }
            }
        }

        asr_json_response([
            'status' => 'success',
            'answered' => $answeredCount,
            'current_page' => $currentPage,
            'resume_url' => asr_base_url() . '?resume=' . urlencode($token),
            'bitrix24' => $b24Partial
        ]);
    }

    asr_json_response(['status' => 'error', 'message' => 'Неизвестное действие'], 400);
} catch (Throwable $e) {
    asr_json_response([
        'status' => 'error',
        'message' => 'Ошибка промежуточного сохранения',
        'debug' => (defined('ASR_DEBUG') && ASR_DEBUG) ? $e->getMessage() : null
    ], 500);
}
