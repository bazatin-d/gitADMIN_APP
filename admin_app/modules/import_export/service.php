<?php
defined('ASR_ADMIN') || exit;

// CSV: единый формат импорта/экспорта результатов.
function asr_results_csv_headers(): array {
    return [
        'Дата заполнения',
        'Статус заполнения',
        'ФИО',
        'Телефон',
        'Email',
        'Город',
        'Пол',
        'Возраст',
        'Роль',
        'Ответ на вопрос 197',
        'Ответ на вопрос 22',
        'Значения графика A-J',
        'UTM-метка',
    ];
}

function asr_csv_output_headers(string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
}

function asr_normalize_graph_scores_for_csv($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (!preg_match_all('/-?\d+/', $value, $matches)) {
        return '';
    }
    $scores = array_slice(array_map('intval', $matches[0]), 0, 10);
    return count($scores) === 10 ? implode(', ', $scores) : '';
}

function asr_result_answer_from_json($json, int $questionNumber): string {
    $answers = json_decode((string)$json, true);
    if (!is_array($answers)) {
        return '';
    }
    $value = $answers[$questionNumber - 1] ?? '';
    return in_array($value, ['+', '?', '-'], true) ? $value : '';
}

function asr_normalize_special_answer($value): string {
    $value = trim((string)$value);
    $map = [
        '+' => '+', '?' => '?', '-' => '-',
        'да' => '+', 'yes' => '+', 'true' => '+', '1' => '+', 'плюс' => '+',
        'не знаю' => '?', 'unknown' => '?',
        'нет' => '-', 'no' => '-', 'false' => '-', '0' => '-', 'минус' => '-',
    ];
    $key = mb_strtolower($value, 'UTF-8');
    return $map[$key] ?? (in_array($value, ['+', '?', '-'], true) ? $value : '');
}

function asr_normalize_csv_date($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }

    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            if ($format === 'Y-m-d' || $format === 'd.m.Y') {
                $dt->setTime(0, 0, 0);
            }
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

function asr_csv_header_key(string $header): string {
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
    $header = mb_strtolower(trim($header), 'UTF-8');
    $header = str_replace(['ё', '–', '—'], ['е', '-', '-'], $header);
    $header = preg_replace('/\s+/u', ' ', $header);
    return $header;
}

function asr_csv_value_by_alias(array $row, array $aliases): string {
    foreach ($aliases as $alias) {
        $key = asr_csv_header_key($alias);
        if (array_key_exists($key, $row)) {
            return trim((string)$row[$key]);
        }
    }
    return '';
}
