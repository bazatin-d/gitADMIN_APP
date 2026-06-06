<?php
/**
 * Возврат сохранённого черновика теста по resume_token.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function asr_resume_response(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
    asr_resume_response(['status' => 'error', 'message' => 'Некорректная ссылка продолжения'], 400);
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, phone, city, gender, age, role, utm, answers, status, current_page FROM oca_results WHERE resume_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        asr_resume_response(['status' => 'error', 'message' => 'Тест по этой ссылке не найден'], 404);
    }

    if (($row['status'] ?? '') === 'completed') {
        asr_resume_response(['status' => 'completed', 'message' => 'Этот тест уже завершён']);
    }

    $answers = json_decode($row['answers'] ?? '[]', true);
    if (!is_array($answers)) {
        $answers = array_fill(0, 200, null);
    }
    $answers = array_slice(array_pad($answers, 200, null), 0, 200);

    asr_resume_response([
        'status' => 'success',
        'id' => (int)$row['id'],
        'token' => $token,
        'current_page' => max(1, min(10, (int)($row['current_page'] ?? 1))),
        'user' => [
            'name' => $row['name'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'city' => $row['city'] ?? '',
            'gender' => $row['gender'] ?? '',
            'age' => $row['age'] ?? '',
            'role' => $row['role'] ?? '',
            'utm' => $row['utm'] ?? ''
        ],
        'answers' => $answers
    ]);
} catch (Throwable $e) {
    asr_resume_response([
        'status' => 'error',
        'message' => 'Ошибка загрузки черновика',
        'debug' => $e->getMessage()
    ], 500);
}
