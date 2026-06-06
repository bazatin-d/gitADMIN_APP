<?php
/**
 * Сервисный слой модуля диагностики.
 * Собирает проверки окружения, файлов и базовых таблиц без побочных действий.
 */

defined('ASR_ADMIN') || exit;

require_once dirname(__DIR__, 3) . '/core/migrations.php';

function asr_diagnostics_bool_label(bool $ok, string $yes = 'есть', string $no = 'нет'): string {
    return $ok ? $yes : $no;
}

function asr_diagnostics_file_check(string $title, string $relativePath): array {
    $root = dirname(__DIR__, 3);
    $exists = file_exists($root . '/' . ltrim($relativePath, '/'));
    return [$title, $exists ? 'есть' : 'нет', $exists];
}

function asr_diagnostics_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_diagnostics_table_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) {
        return [];
    }
}

function asr_diagnostics_collect_checks(PDO $pdo): array {
    $checks = [];

    $checks[] = ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=')];
    $checks[] = ['PDO', extension_loaded('pdo') ? 'включён' : 'не найден', extension_loaded('pdo')];
    $checks[] = ['PDO MySQL', extension_loaded('pdo_mysql') ? 'включён' : 'не найден', extension_loaded('pdo_mysql')];
    $checks[] = ['cURL', extension_loaded('curl') ? 'включён' : 'не найден', extension_loaded('curl')];
    $checks[] = ['JSON', extension_loaded('json') ? 'включён' : 'не найден', extension_loaded('json')];
    $checks[] = ['mbstring', extension_loaded('mbstring') ? 'включён' : 'не найден', extension_loaded('mbstring')];

    $migrationFiles = function_exists('asr_migration_files') ? asr_migration_files() : [];
    $registryExists = function_exists('asr_migration_registry_exists') ? asr_migration_registry_exists($pdo) : false;
    $checks[] = ['Каталог database/migrations', is_dir(dirname(__DIR__, 4) . '/database/migrations') ? 'есть' : 'нет', is_dir(dirname(__DIR__, 4) . '/database/migrations')];
    $checks[] = ['Файлы SQL-миграций', count($migrationFiles) . ' шт.', count($migrationFiles) > 0];
    $checks[] = ['Таблица app_migrations', $registryExists ? 'есть' : 'нет', $registryExists];
    if ($registryExists && function_exists('asr_migration_status')) {
        $migrationStatus = asr_migration_status($pdo);
        $pending = 0;
        foreach ($migrationStatus as $migrationRow) {
            if (empty($migrationRow['applied'])) {
                $pending++;
            }
        }
        $checks[] = ['Неприменённые миграции', $pending === 0 ? 'нет' : ($pending . ' шт.'), $pending === 0];
    }


    $settingsTableExists = function_exists('asr_settings_table_exists')
        ? asr_settings_table_exists($pdo)
        : asr_diagnostics_table_exists($pdo, 'oca_settings');
    $checks[] = ['Таблица oca_settings', $settingsTableExists ? 'есть' : 'нет', $settingsTableExists];

    foreach (['oca_results', 'oca_users', 'oca_short_urls', 'oca_utm_presets', 'oca_admin_menu_items'] as $table) {
        $exists = asr_diagnostics_table_exists($pdo, $table);
        $checks[] = ['Таблица ' . $table, $exists ? 'есть' : 'нет', $exists];
    }

    $checks[] = asr_diagnostics_file_check('Файл osaPHPavs.php', 'osaPHPavs.php');
    $checks[] = asr_diagnostics_file_check('Интеграция Bitrix24', 'b24_integration/sync_test.php');
    $checks[] = asr_diagnostics_file_check('save_progress.php', 'save_progress.php');
    $checks[] = asr_diagnostics_file_check('u.php', 'u.php');

    $columns = asr_diagnostics_table_columns($pdo, 'oca_results');
    $neededColumns = ['status', 'current_page', 'resume_token', 'completed_at'];
    foreach ($neededColumns as $col) {
        $ok = in_array($col, $columns, true);
        $checks[] = ['Колонка oca_results.' . $col, $ok ? 'есть' : 'нет', $ok];
    }

    return $checks;
}
