<?php
defined('ASR_ADMIN') || exit;

/**
 * Лёгкий реестр SQL-миграций.
 *
 * Важно: этот файл НЕ применяет миграции автоматически. Он только помогает
 * диагностике показать, какие SQL-файлы есть в проекте и какие из них отмечены
 * в таблице app_migrations как выполненные.
 */

function asr_migrations_dir(): string {
    return dirname(__DIR__, 2) . '/database/migrations';
}

function asr_migrations_registry_table(): string {
    return 'app_migrations';
}

function asr_migration_registry_exists(PDO $pdo): bool {
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([asr_migrations_registry_table()]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_migration_files(): array {
    $dir = asr_migrations_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    return array_map(static function(string $file): array {
        return [
            'file' => $file,
            'name' => basename($file),
        ];
    }, $files);
}

function asr_applied_migrations(PDO $pdo): array {
    if (!asr_migration_registry_exists($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->query('SELECT migration, applied_at FROM app_migrations ORDER BY migration ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];
        foreach ($rows as $row) {
            $name = (string)($row['migration'] ?? '');
            if ($name !== '') {
                $map[$name] = (string)($row['applied_at'] ?? '');
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function asr_migration_status(PDO $pdo): array {
    $files = asr_migration_files();
    $applied = asr_applied_migrations($pdo);
    $rows = [];

    foreach ($files as $file) {
        $name = $file['name'];
        $rows[] = [
            'name' => $name,
            'applied' => array_key_exists($name, $applied),
            'applied_at' => $applied[$name] ?? '',
        ];
    }

    return $rows;
}
