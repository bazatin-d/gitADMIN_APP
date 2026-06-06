<?php
/**
 * CLI-чистка старых файлов модуля «Чат-боты».
 *
 * Безопасный запуск для проверки:
 * php admin_app/modules/telegram_bots/cron_cleanup_uploads.php --dry-run
 *
 * Реальная чистка:
 * php admin_app/modules/telegram_bots/cron_cleanup_uploads.php --run
 *
 * Политика хранения:
 * - файлы ручного чата: 90 дней;
 * - файлы завершённых/отменённых/ошибочных рассылок: 180 дней;
 * - временные файлы: 24 часа.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ASR_ADMIN', true);
require_once dirname(__DIR__, 3) . '/config.php';

$argvList = $argv ?? [];
$isRun = in_array('--run', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isRun;
$root = realpath(dirname(__DIR__, 3));
if (!$root) {
    echo "error=project root not found" . PHP_EOL;
    exit(1);
}

$stats = [
    'mode' => $isRun ? 'run' : 'dry-run',
    'chat_checked' => 0,
    'chat_deleted' => 0,
    'broadcast_checked' => 0,
    'broadcast_deleted' => 0,
    'tmp_checked' => 0,
    'tmp_deleted' => 0,
    'bytes' => 0,
    'errors' => 0,
];

function asr_tg_cleanup_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_cleanup_safe_absolute_path(string $root, string $relative): ?string {
    $relative = trim(str_replace('\\', '/', $relative));
    $relative = ltrim($relative, '/');
    if ($relative === '') return null;
    if (strpos($relative, '..') !== false) return null;
    if (strpos($relative, 'uploads/telegram_bots/') !== 0) return null;
    $absolute = $root . '/' . $relative;
    $dir = realpath(dirname($absolute));
    $allowedRoot = realpath($root . '/uploads/telegram_bots');
    if (!$dir || !$allowedRoot || strpos($dir, $allowedRoot) !== 0) return null;
    return $absolute;
}

function asr_tg_cleanup_unlink(string $path, bool $isRun, array &$stats): bool {
    if (!is_file($path)) return false;
    $size = filesize($path);
    if ($isRun) {
        if (!@unlink($path)) {
            $stats['errors']++;
            echo 'error=delete_failed path=' . $path . PHP_EOL;
            return false;
        }
    }
    $stats['bytes'] += max(0, (int)$size);
    return true;
}

function asr_tg_cleanup_attachment_paths_from_payload(array $payload): array {
    $paths = [];
    if (!empty($payload['attachment']) && is_array($payload['attachment'])) {
        $path = (string)($payload['attachment']['file_path'] ?? '');
        if ($path !== '') $paths[] = $path;
    }
    return array_values(array_unique($paths));
}

function asr_tg_cleanup_mark_attachment_deleted(array $payload): array {
    if (!empty($payload['attachment']) && is_array($payload['attachment'])) {
        $payload['attachment']['file_deleted'] = true;
        $payload['attachment']['file_deleted_at'] = date('Y-m-d H:i:s');
        $payload['attachment']['file_path'] = null;
        $payload['attachment']['file_url'] = null;
    }
    return $payload;
}

function asr_tg_cleanup_payload_media_paths(array $payload): array {
    $paths = [];
    $walker = static function ($value) use (&$walker, &$paths): void {
        if (!is_array($value)) return;
        foreach (['media_file_path', 'local_file_path', 'file_path'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) {
                $paths[] = $value[$key];
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) $walker($child);
        }
    };
    $walker($payload);
    return array_values(array_unique($paths));
}

function asr_tg_cleanup_mark_payload_media_deleted(array $payload): array {
    $walker = static function (&$value) use (&$walker): void {
        if (!is_array($value)) return;
        foreach (['media_file_path', 'local_file_path', 'file_path', 'media_url', 'file_url'] as $key) {
            if (array_key_exists($key, $value) && is_string($value[$key]) && $value[$key] !== '') {
                $value[$key] = null;
            }
        }
        if (array_key_exists('media_file_path', $value) || array_key_exists('local_file_path', $value) || array_key_exists('file_path', $value)) {
            $value['file_deleted'] = true;
            $value['file_deleted_at'] = date('Y-m-d H:i:s');
        }
        foreach ($value as &$child) {
            if (is_array($child)) $walker($child);
        }
        unset($child);
    };
    $walker($payload);
    return $payload;
}

function asr_tg_cleanup_old_tmp_files(string $root, bool $isRun, array &$stats): void {
    $dir = $root . '/uploads/telegram_bots/tmp';
    if (!is_dir($dir)) return;
    $cutoff = time() - 86400;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if (!$item->isFile()) continue;
        $stats['tmp_checked']++;
        $path = $item->getPathname();
        if ($item->getMTime() > $cutoff) continue;
        if (asr_tg_cleanup_unlink($path, $isRun, $stats)) {
            $stats['tmp_deleted']++;
            echo 'tmp_file=' . $path . PHP_EOL;
        }
    }
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('PDO-подключение не найдено в config.php.');
    }

    if (asr_tg_cleanup_table_exists($pdo, 'oca_telegram_bot_messages')) {
        $stmt = $pdo->query("SELECT id, payload_json FROM oca_telegram_bot_messages WHERE direction = 'out' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND payload_json IS NOT NULL AND payload_json LIKE '%\"attachment\"%' LIMIT 5000");
        $update = $pdo->prepare('UPDATE oca_telegram_bot_messages SET payload_json = ? WHERE id = ?');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $stats['chat_checked']++;
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) continue;
            $paths = asr_tg_cleanup_attachment_paths_from_payload($payload);
            if (!$paths) continue;
            $deletedAny = false;
            foreach ($paths as $relative) {
                $absolute = asr_tg_cleanup_safe_absolute_path($root, $relative);
                if (!$absolute || !is_file($absolute)) continue;
                if (asr_tg_cleanup_unlink($absolute, $isRun, $stats)) {
                    $deletedAny = true;
                    $stats['chat_deleted']++;
                    echo 'chat_file=' . $relative . PHP_EOL;
                }
            }
            if ($deletedAny && $isRun) {
                $payload = asr_tg_cleanup_mark_attachment_deleted($payload);
                $update->execute([json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$row['id']]);
            }
        }
    }

    if (asr_tg_cleanup_table_exists($pdo, 'oca_telegram_bot_broadcasts')) {
        $stmt = $pdo->query("SELECT id, media_file_path, payload_json FROM oca_telegram_bot_broadcasts WHERE status IN ('sent','cancelled','failed') AND COALESCE(finished_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 180 DAY) AND (media_file_path IS NOT NULL OR payload_json IS NOT NULL) LIMIT 5000");
        $update = $pdo->prepare('UPDATE oca_telegram_bot_broadcasts SET media_file_path = NULL, media_url = NULL, payload_json = ?, updated_at = NOW() WHERE id = ?');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $stats['broadcast_checked']++;
            $paths = [];
            if (!empty($row['media_file_path'])) $paths[] = (string)$row['media_file_path'];
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (is_array($payload)) $paths = array_merge($paths, asr_tg_cleanup_payload_media_paths($payload));
            $paths = array_values(array_unique(array_filter($paths)));
            if (!$paths) continue;
            $deletedAny = false;
            foreach ($paths as $relative) {
                $absolute = asr_tg_cleanup_safe_absolute_path($root, $relative);
                if (!$absolute || !is_file($absolute)) continue;
                if (asr_tg_cleanup_unlink($absolute, $isRun, $stats)) {
                    $deletedAny = true;
                    $stats['broadcast_deleted']++;
                    echo 'broadcast_file=' . $relative . PHP_EOL;
                }
            }
            if ($deletedAny && $isRun) {
                $newPayload = is_array($payload) ? asr_tg_cleanup_mark_payload_media_deleted($payload) : null;
                $update->execute([$newPayload ? json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, (int)$row['id']]);
            }
        }
    }

    asr_tg_cleanup_old_tmp_files($root, $isRun, $stats);

    echo 'mode=' . $stats['mode']
        . ' chat_checked=' . $stats['chat_checked']
        . ' chat_deleted=' . $stats['chat_deleted']
        . ' broadcast_checked=' . $stats['broadcast_checked']
        . ' broadcast_deleted=' . $stats['broadcast_deleted']
        . ' tmp_checked=' . $stats['tmp_checked']
        . ' tmp_deleted=' . $stats['tmp_deleted']
        . ' bytes=' . $stats['bytes']
        . ' errors=' . $stats['errors']
        . PHP_EOL;

    exit($stats['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
