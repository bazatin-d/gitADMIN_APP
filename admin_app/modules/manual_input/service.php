<?php
defined('ASR_ADMIN') || exit;

function asr_manual_input_clean_text(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $maxLength) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function asr_manual_input_normalize_created_at(string $createdDate): string
{
    $createdDate = trim($createdDate);
    if ($createdDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdDate)) {
        return $createdDate . ' ' . date('H:i:s');
    }

    return date('Y-m-d H:i:s');
}

function asr_manual_input_collect_answers(array $postedAnswers): array
{
    $answers = [];

    for ($i = 1; $i <= 200; $i++) {
        $answer = $postedAnswers[$i] ?? '';
        if (!in_array($answer, ['+', '?', '-'], true)) {
            throw new InvalidArgumentException('Заполните ответы на все 200 вопросов.');
        }
        $answers[] = $answer;
    }

    return $answers;
}

function asr_manual_input_calc_scores_from_answers(array $answers, string $gender, string $age): string
{
    if (!function_exists('OcaCalk')) {
        $calcPath = dirname(__DIR__, 3) . '/osaPHPavs.php';
        if (is_file($calcPath)) {
            require_once $calcPath;
        }
    }

    if (!function_exists('OcaCalk')) {
        throw new RuntimeException('Не найдена функция расчёта OcaCalk. Проверьте файл osaPHPavs.php.');
    }

    $calcInput = [];
    $calcInput[0] = ($gender === 'Женский') ? 2 : 1;
    $calcInput[1] = ($age === 'Младше 18 лет') ? 2 : 1;

    for ($i = 0; $i < 200; $i++) {
        if ($answers[$i] === '+') {
            $calcInput[$i + 2] = 1;
        } elseif ($answers[$i] === '?') {
            $calcInput[$i + 2] = 2;
        } else {
            $calcInput[$i + 2] = 3;
        }
    }

    return (string)OcaCalk($calcInput);
}

function asr_manual_input_collect_scores(array $postedScores): string
{
    $letters = ['A','B','C','D','E','F','G','H','I','J'];
    $scores = [];

    foreach ($letters as $letter) {
        $value = trim((string)($postedScores[$letter] ?? ''));
        if ($value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException('Заполните все показатели графика A–J числами.');
        }
        $scores[] = (int)$value;
    }

    return implode(', ', $scores);
}

function asr_manual_input_build_payload(array $post): array
{
    $name   = asr_manual_input_clean_text((string)($post['name'] ?? ''));
    $email  = asr_manual_input_clean_text((string)($post['email'] ?? ''));
    $phone  = asr_manual_input_clean_text((string)($post['phone'] ?? ''));
    $city   = asr_manual_input_clean_text((string)($post['city'] ?? ''));
    $role   = asr_manual_input_clean_text((string)($post['role'] ?? ''));
    $gender = asr_manual_input_clean_text((string)($post['gender'] ?? 'Мужской'));
    $age    = asr_manual_input_clean_text((string)($post['age'] ?? 'Старше 18 лет'));
    $mode   = (string)($post['manual_mode'] ?? 'answers');

    if ($name === '') {
        throw new InvalidArgumentException('Укажите ФИО для ручного результата.');
    }

    if (!in_array($gender, ['Мужской', 'Женский'], true)) {
        $gender = 'Мужской';
    }

    if (!in_array($age, ['Старше 18 лет', 'Младше 18 лет'], true)) {
        $age = 'Старше 18 лет';
    }

    if (!in_array($mode, ['answers', 'scores'], true)) {
        $mode = 'answers';
    }

    $answers = [];
    $graphScores = '';
    $falsePointB = 0;
    $falsePointE = 0;

    if ($mode === 'answers') {
        $answers = asr_manual_input_collect_answers($post['answers'] ?? []);
        $graphScores = asr_manual_input_calc_scores_from_answers($answers, $gender, $age);
        $falsePointB = (isset($answers[196]) && $answers[196] === '+') ? 1 : 0; // вопрос 197
        $falsePointE = (isset($answers[21]) && $answers[21] === '+') ? 1 : 0;   // вопрос 22
    } else {
        $graphScores = asr_manual_input_collect_scores($post['scores'] ?? []);
        $falsePoints = $post['false_points'] ?? [];
        $falsePointB = in_array('B', $falsePoints, true) ? 1 : 0;
        $falsePointE = in_array('E', $falsePoints, true) ? 1 : 0;
    }

    return [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'city' => $city,
        'gender' => $gender,
        'age' => $age,
        'role' => $role,
        'utm' => 'manual_input',
        'graph_scores' => $graphScores,
        'answers' => $answers,
        'false_point_b' => $falsePointB,
        'false_point_e' => $falsePointE,
        'manual_input_mode' => $mode,
        'created_at' => asr_manual_input_normalize_created_at((string)($post['created_date'] ?? '')),
    ];
}

function asr_manual_input_sync_to_bitrix(PDO $pdo, int $resultId, array $payload): void
{
    $integrationPath = dirname(__DIR__, 3) . '/b24_integration/sync_test.php';
    if (!is_file($integrationPath)) {
        return;
    }

    require_once $integrationPath;
    if (!function_exists('sendTestToBitrix24')) {
        return;
    }

    $baseUrl = function_exists('asr_config_app_base_url') ? asr_config_app_base_url() : 'https://localhost/';
    $b24Result = sendTestToBitrix24([
        'test_id' => $resultId,
        'name' => $payload['name'],
        'phone' => $payload['phone'],
        'email' => $payload['email'],
        'city' => $payload['city'],
        'gender' => $payload['gender'],
        'age' => $payload['age'],
        'role' => $payload['role'],
        'utm' => $payload['utm'],
        'manager_link' => $baseUrl . 'admin.php?view=' . $resultId,
        'client_link' => $baseUrl . 'admin.php?shared=' . encodeId($resultId),
    ]);

    if ($b24Result && !empty($b24Result['deal_url'])) {
        asr_manual_input_update_crm_links($pdo, $resultId, (string)$b24Result['deal_url'], (string)($b24Result['contact_url'] ?? ''));
    }
}
