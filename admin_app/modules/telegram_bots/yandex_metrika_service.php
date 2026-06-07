<?php
/**
 * Очередь офлайн-конверсий Яндекс.Метрики для сценариев Telegram Bots.
 * Runtime сценария только ставит событие в oca_telegram_bot_yandex_metrika_events.
 * Этот слой вызывается из cron и отправляет события пачками в API Метрики.
 */

if (!function_exists('asr_tg_yandex_metrika_ensure_runtime_schema')) {
function asr_tg_yandex_metrika_ensure_runtime_schema(PDO $pdo): void {
    if (function_exists('asr_tg_repository_ensure_scenario_schema')) {
        asr_tg_repository_ensure_scenario_schema($pdo);
    }
    $alters = [
        "ALTER TABLE `oca_telegram_bot_yandex_metrika_events` ADD COLUMN `processing_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`",
        "ALTER TABLE `oca_telegram_bot_yandex_metrika_events` ADD COLUMN `yandex_upload_id` VARCHAR(120) NULL DEFAULT NULL AFTER `processing_at`",
        "ALTER TABLE `oca_telegram_bot_yandex_metrika_events` ADD COLUMN `response_json` JSON NULL AFTER `yandex_upload_id`",
        "ALTER TABLE `oca_telegram_bot_yandex_metrika_events` ADD KEY `idx_tg_ym_events_processing` (`status`, `processing_at`)",
    ];
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $ignored) {}
    }
}
}

if (!function_exists('asr_tg_yandex_metrika_stats')) {
function asr_tg_yandex_metrika_stats(PDO $pdo): array {
    asr_tg_yandex_metrika_ensure_runtime_schema($pdo);
    $result = ['pending'=>0,'processing'=>0,'retry'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) cnt FROM oca_telegram_bot_yandex_metrika_events GROUP BY status");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $status = (string)($row['status'] ?? '');
            if ($status !== '') $result[$status] = (int)($row['cnt'] ?? 0);
        }
    } catch (Throwable $ignored) {}
    return $result;
}
}

if (!function_exists('asr_tg_yandex_metrika_format_stats')) {
function asr_tg_yandex_metrika_format_stats(array $stats, string $prefix = 'ym'): string {
    $parts = [];
    foreach (['pending','processing','retry','sent','failed','skipped'] as $key) {
        $parts[] = $prefix . '_' . $key . '=' . (int)($stats[$key] ?? 0);
    }
    return implode(' ', $parts);
}
}

if (!function_exists('asr_tg_yandex_metrika_csv_escape')) {
function asr_tg_yandex_metrika_csv_escape(string $value): string {
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    if (strpbrk($value, ",\"") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}
}

if (!function_exists('asr_tg_yandex_metrika_action_settings')) {
function asr_tg_yandex_metrika_action_settings(PDO $pdo, int $blockId, int $actionIndex): array {
    if ($blockId <= 0) return [];
    try {
        $stmt = $pdo->prepare('SELECT settings_json FROM oca_telegram_bot_scenario_blocks WHERE id = ? LIMIT 1');
        $stmt->execute([$blockId]);
        $json = (string)($stmt->fetchColumn() ?: '');
        $settings = json_decode($json, true);
        if (!is_array($settings)) return [];
        $actions = $settings['actions'] ?? [];
        if (!is_array($actions)) return [];
        if (isset($actions[$actionIndex]) && is_array($actions[$actionIndex])) {
            return $actions[$actionIndex];
        }
        foreach ($actions as $action) {
            if (is_array($action) && (string)($action['type'] ?? '') === 'yandex_metrika') {
                return $action;
            }
        }
    } catch (Throwable $ignored) {}
    return [];
}
}

if (!function_exists('asr_tg_yandex_metrika_compact_error')) {
function asr_tg_yandex_metrika_compact_error(string $message, string $body = ''): string {
    $message = trim($message);
    $body = trim($body);
    if ($body !== '') $message .= ($message !== '' ? ' | ' : '') . mb_substr($body, 0, 1200, 'UTF-8');
    return mb_substr($message !== '' ? $message : 'Неизвестная ошибка Яндекс.Метрики.', 0, 2000, 'UTF-8');
}
}

if (!function_exists('asr_tg_yandex_metrika_upload_csv')) {
function asr_tg_yandex_metrika_upload_csv(string $counterId, string $token, string $csv): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'На сервере недоступен PHP cURL.'];
    $counterId = preg_replace('~[^0-9]~', '', $counterId);
    if ($counterId === '') return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'Не указан номер счётчика.'];
    if (trim($token) === '') return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'Не указан OAuth-токен.'];

    $tmp = tempnam(sys_get_temp_dir(), 'ym_offline_');
    if ($tmp === false) return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'Не удалось создать временный CSV-файл.'];
    file_put_contents($tmp, $csv);
    $url = 'https://api-metrika.yandex.net/management/v1/counter/' . rawurlencode($counterId) . '/offline_conversions/upload?client_id_type=CLIENT_ID';
    $ch = curl_init($url);
    $file = class_exists('CURLFile') ? new CURLFile($tmp, 'text/csv', 'offline-conversions.csv') : '@' . $tmp;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . trim($token), 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['file' => $file],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $bodyString = is_string($body) ? $body : '';
    return ['ok'=>($err === '' && $code >= 200 && $code < 300),'http_code'=>$code,'body'=>$bodyString,'error'=>$err];
}
}

if (!function_exists('asr_tg_yandex_metrika_mark_events')) {
function asr_tg_yandex_metrika_mark_events(PDO $pdo, array $ids, string $status, string $error = '', array $response = []): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $responseJson = $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    if ($responseJson === false) $responseJson = null;
    $uploadId = '';
    foreach (['id','uploading_id','upload_id'] as $key) {
        if (isset($response[$key]) && (string)$response[$key] !== '') { $uploadId = mb_substr((string)$response[$key], 0, 120, 'UTF-8'); break; }
    }
    if ($status === 'sent') {
        $sql = "UPDATE oca_telegram_bot_yandex_metrika_events SET status = 'sent', sent_at = NOW(), processing_at = NULL, last_error = NULL, yandex_upload_id = ?, response_json = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $params = [$uploadId !== '' ? $uploadId : null, $responseJson];
    } elseif ($status === 'retry') {
        $sql = "UPDATE oca_telegram_bot_yandex_metrika_events SET status = 'retry', processing_at = NULL, last_error = ?, response_json = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $params = [$error !== '' ? $error : null, $responseJson];
    } else {
        $sql = "UPDATE oca_telegram_bot_yandex_metrika_events SET status = 'failed', processing_at = NULL, last_error = ?, response_json = ?, updated_at = NOW() WHERE id IN ($placeholders)";
        $params = [$error !== '' ? $error : null, $responseJson];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $ids));
}
}

if (!function_exists('asr_tg_yandex_metrika_process_queue')) {
function asr_tg_yandex_metrika_process_queue(PDO $pdo, int $limit = 100): array {
    asr_tg_yandex_metrika_ensure_runtime_schema($pdo);
    $limit = max(1, min(500, $limit));
    $maxAttempts = 5;
    $result = ['processed'=>0,'sent'=>0,'failed'=>0,'retry'=>0,'skipped'=>0,'batches'=>0,'stale_processing_reset'=>0];
    try {
        $reset = $pdo->prepare("UPDATE oca_telegram_bot_yandex_metrika_events SET status = 'retry', processing_at = NULL, last_error = 'Сброшена зависшая отправка Яндекс.Метрики.' WHERE status = 'processing' AND processing_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $reset->execute();
        $result['stale_processing_reset'] = (int)$reset->rowCount();
    } catch (Throwable $ignored) {}

    $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_yandex_metrika_events WHERE status IN ('pending','retry') AND attempts < ? AND client_id IS NOT NULL AND client_id <> '' ORDER BY created_at ASC, id ASC LIMIT $limit");
    $stmt->execute([$maxAttempts]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return $result;

    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $mark = $pdo->prepare("UPDATE oca_telegram_bot_yandex_metrika_events SET status = 'processing', processing_at = NOW(), attempts = attempts + 1, updated_at = NOW() WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status IN ('pending','retry')");
    $mark->execute($ids);

    $groups = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $action = asr_tg_yandex_metrika_action_settings($pdo, (int)($row['block_id'] ?? 0), (int)($row['action_index'] ?? 0));
        $token = trim((string)($action['token'] ?? ''));
        $counterId = preg_replace('~[^0-9]~', '', (string)($row['counter_id'] ?? ''));
        $target = trim((string)($row['target'] ?? ''));
        $clientId = trim((string)($row['client_id'] ?? ''));
        if ($id <= 0) continue;
        if ($token === '' || $counterId === '' || $target === '' || $clientId === '') {
            asr_tg_yandex_metrika_mark_events($pdo, [$id], 'failed', 'Не удалось отправить в Яндекс.Метрику: нет токена, счётчика, цели или Client ID.');
            $result['failed']++; $result['processed']++;
            continue;
        }
        $key = hash('sha256', $counterId . "\n" . $target . "\n" . $token);
        if (!isset($groups[$key])) $groups[$key] = ['counter_id'=>$counterId,'target'=>$target,'token'=>$token,'rows'=>[]];
        $groups[$key]['rows'][] = $row;
    }

    foreach ($groups as $group) {
        $result['batches']++;
        $csv = "ClientId,Target,DateTime\n";
        $groupIds = [];
        foreach ($group['rows'] as $row) {
            $groupIds[] = (int)$row['id'];
            $time = strtotime((string)($row['conversion_at'] ?? '')) ?: time();
            if ($time >= time()) $time = time() - 1;
            $csv .= asr_tg_yandex_metrika_csv_escape((string)$row['client_id']) . ',' . asr_tg_yandex_metrika_csv_escape((string)$row['target']) . ',' . (int)$time . "\n";
        }
        $upload = asr_tg_yandex_metrika_upload_csv((string)$group['counter_id'], (string)$group['token'], $csv);
        $body = (string)($upload['body'] ?? '');
        $decoded = json_decode($body, true);
        $response = is_array($decoded) ? $decoded : ['raw'=>mb_substr($body, 0, 4000, 'UTF-8')];
        $response['http_code'] = (int)($upload['http_code'] ?? 0);
        $result['processed'] += count($groupIds);
        if (!empty($upload['ok'])) {
            asr_tg_yandex_metrika_mark_events($pdo, $groupIds, 'sent', '', $response);
            $result['sent'] += count($groupIds);
            continue;
        }
        $http = (int)($upload['http_code'] ?? 0);
        $error = asr_tg_yandex_metrika_compact_error('HTTP ' . $http . ' при отправке офлайн-конверсий в Яндекс.Метрику. ' . (string)($upload['error'] ?? ''), $body);
        $retryable = ($http === 0 || $http === 429 || $http >= 500);
        $retryIds = []; $failedIds = [];
        foreach ($group['rows'] as $row) {
            $attemptsAfter = (int)($row['attempts'] ?? 0) + 1;
            if ($retryable && $attemptsAfter < $maxAttempts) $retryIds[] = (int)$row['id']; else $failedIds[] = (int)$row['id'];
        }
        if ($retryIds) { asr_tg_yandex_metrika_mark_events($pdo, $retryIds, 'retry', $error, $response); $result['retry'] += count($retryIds); }
        if ($failedIds) { asr_tg_yandex_metrika_mark_events($pdo, $failedIds, 'failed', $error, $response); $result['failed'] += count($failedIds); }
    }
    return $result;
}
}
