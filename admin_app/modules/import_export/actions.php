<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';

// Экспорт результатов в CSV в том же формате, который принимает импорт.
if (isAdmin() && ($_GET['action'] ?? '') === 'export_results') {
    asr_csv_output_headers('asr_results_' . date('Y-m-d_H-i') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, asr_results_csv_headers(), ';');

    $stmt = $pdo->query("SELECT * FROM oca_results ORDER BY id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $q197 = asr_result_answer_from_json($row['answers'] ?? '', 197);
        $q22 = asr_result_answer_from_json($row['answers'] ?? '', 22);

        if ($q197 === '' && !empty($row['false_point_b'])) {
            $q197 = '+';
        }
        if ($q22 === '' && !empty($row['false_point_e'])) {
            $q22 = '+';
        }

        fputcsv($out, [
            $row['created_at'] ?? '',
            $row['status'] ?? 'completed',
            $row['name'] ?? '',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['city'] ?? '',
            $row['gender'] ?? '',
            $row['age'] ?? '',
            $row['role'] ?? '',
            $q197,
            $q22,
            asr_normalize_graph_scores_for_csv($row['graph_scores'] ?? ''),
            $row['utm'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// Образец CSV для ручного заполнения.
if (isAdmin() && ($_GET['action'] ?? '') === 'download_import_sample') {
    asr_csv_output_headers('asr_results_import_sample.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, asr_results_csv_headers(), ';');
    fputcsv($out, [
        date('Y-m-d H:i:s'),
        'completed',
        'Иван Иванов',
        '+7 700 000 00 00',
        'ivan@example.com',
        'Алматы',
        'Мужской',
        'Старше 18 лет',
        'Владелец',
        '+',
        '-',
        '36, 16, -64, 30, -12, 8, -72, 48, 60, -46',
        'utm_source=telegram&utm_medium=post&utm_campaign=test',
    ], ';');
    fclose($out);
    exit;
}

// Импорт результатов из CSV в формате экспорта.
if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_results_csv') {
    $redirectBase = 'admin.php?tab=import_export';

    if (empty($_FILES['results_csv']['tmp_name']) || !is_uploaded_file($_FILES['results_csv']['tmp_name'])) {
        header('Location: ' . $redirectBase . '&import_error=' . urlencode('Файл CSV не выбран.'));
        exit;
    }

    $handle = fopen($_FILES['results_csv']['tmp_name'], 'r');
    if (!$handle) {
        header('Location: ' . $redirectBase . '&import_error=' . urlencode('Не удалось открыть CSV-файл.'));
        exit;
    }

    $rawHeaders = fgetcsv($handle, 0, ';');
    if (!$rawHeaders || count($rawHeaders) < 5) {
        fclose($handle);
        header('Location: ' . $redirectBase . '&import_error=' . urlencode('В CSV не найдена строка заголовков.'));
        exit;
    }

    $headers = array_map('asr_csv_header_key', $rawHeaders);
    $imported = 0;
    $skipped = 0;
    $line = 1;

    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        $line++;
        if (count(array_filter($data, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $row = [];
        foreach ($headers as $i => $header) {
            $row[$header] = $data[$i] ?? '';
        }

        $graphScores = asr_normalize_graph_scores_for_csv(asr_csv_value_by_alias($row, ['Значения графика A-J', 'Значения графика', 'graph_scores', 'Баллы профиля']));
        if ($graphScores === '') {
            $skipped++;
            continue;
        }

        $q197 = asr_normalize_special_answer(asr_csv_value_by_alias($row, ['Ответ на вопрос 197', 'Вопрос 197', '197', 'Q197']));
        $q22 = asr_normalize_special_answer(asr_csv_value_by_alias($row, ['Ответ на вопрос 22', 'Вопрос 22', '22', 'Q22']));

        $answers = array_fill(0, 200, null);
        if ($q197 !== '') {
            $answers[196] = $q197;
        }
        if ($q22 !== '') {
            $answers[21] = $q22;
        }

        $status = mb_strtolower(asr_csv_value_by_alias($row, ['Статус заполнения', 'Статус', 'status']), 'UTF-8');
        $status = in_array($status, ['completed', 'in_progress'], true) ? $status : 'completed';
        $createdAt = asr_normalize_csv_date(asr_csv_value_by_alias($row, ['Дата заполнения', 'Дата', 'created_at']));

        $insert = [
            'created_at' => $createdAt,
            'name' => asr_csv_value_by_alias($row, ['ФИО', 'Имя', 'name']),
            'phone' => asr_csv_value_by_alias($row, ['Телефон', 'phone']),
            'email' => asr_csv_value_by_alias($row, ['Email', 'E-mail', 'email']),
            'city' => asr_csv_value_by_alias($row, ['Город', 'city']),
            'gender' => asr_csv_value_by_alias($row, ['Пол', 'gender']),
            'age' => asr_csv_value_by_alias($row, ['Возраст', 'age']),
            'role' => asr_csv_value_by_alias($row, ['Роль', 'Должность', 'role']),
            'utm' => asr_csv_value_by_alias($row, ['UTM-метка', 'UTM', 'utm']),
            'graph_scores' => $graphScores,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ];

        if (asr_table_column_exists($pdo, 'oca_results', 'status')) {
            $insert['status'] = $status;
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'current_page')) {
            $insert['current_page'] = ($status === 'completed') ? 10 : 1;
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'false_point_b')) {
            $insert['false_point_b'] = ($q197 === '+') ? 1 : 0;
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'false_point_e')) {
            $insert['false_point_e'] = ($q22 === '+') ? 1 : 0;
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'manual_input_mode')) {
            $insert['manual_input_mode'] = 'csv_import';
        }
        if ($status === 'completed' && asr_table_column_exists($pdo, 'oca_results', 'completed_at')) {
            $insert['completed_at'] = $createdAt;
        }

        $cols = array_keys($insert);
        $sql = 'INSERT INTO oca_results (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($insert));
            $imported++;
        } catch (Throwable $e) {
            $skipped++;
        }
    }

    fclose($handle);
    header('Location: ' . $redirectBase . '&imported=' . $imported . '&skipped=' . $skipped);
    exit;
}
