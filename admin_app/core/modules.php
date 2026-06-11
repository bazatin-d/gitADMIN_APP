<?php
defined('ASR_ADMIN') || exit;

/**
 * Реестр модулей админки.
 *
 * Новый модуль должен иметь файл:
 * admin_app/modules/{module_id}/module.php
 *
 * Минимальный манифест:
 * return [
 *   'id' => 'module_id',
 *   'title' => 'Название',
 *   'tab' => 'module_id',              // если у модуля есть страница
 *   'permission' => 'admin|results|work_results|public',
 *   'page' => __DIR__ . '/pages/index.php',
 *   'actions' => __DIR__ . '/actions.php',
 * ];
 */

function asr_module_action_order(string $id): int {
    $order = [
        'dashboard' => 1,
        'access_vault' => 5,
        'bitrix24' => 10,
        'diagnostics' => 20,
        'emails' => 30,
        'manual_input' => 40,
        'import_export' => 50,
        'results' => 60,
        'menu' => 70,
        'settings' => 80,
        'telegram_bots' => 85,
        'org_structure' => 88,
        'users' => 90,
        'url_shortener' => 100,
    ];
    return $order[$id] ?? 1000;
}

function asr_admin_modules(): array {
    static $modules = null;
    if ($modules !== null) {
        return $modules;
    }

    $modules = [];
    $baseDir = dirname(__DIR__) . '/modules';
    if (!is_dir($baseDir)) {
        return $modules;
    }

    $manifestFiles = glob($baseDir . '/*/module.php') ?: [];
    foreach ($manifestFiles as $manifestFile) {
        $manifest = require $manifestFile;
        if (!is_array($manifest)) {
            continue;
        }

        $id = trim((string)($manifest['id'] ?? ''));
        if ($id === '') {
            $id = basename(dirname($manifestFile));
        }
        if (!preg_match('/^[a-z0-9_\-]+$/', $id)) {
            continue;
        }

        $manifest['id'] = $id;
        $manifest['_manifest'] = $manifestFile;
        $manifest['_dir'] = dirname($manifestFile);
        $manifest['_action_order'] = asr_module_action_order($id);
        $modules[$id] = $manifest;
    }

    uasort($modules, static function(array $a, array $b): int {
        $ao = (int)($a['_action_order'] ?? 1000);
        $bo = (int)($b['_action_order'] ?? 1000);
        if ($ao === $bo) {
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        }
        return $ao <=> $bo;
    });

    return $modules;
}

function asr_admin_modules_by_tab(): array {
    static $byTab = null;
    if ($byTab !== null) {
        return $byTab;
    }

    $byTab = [];
    foreach (asr_admin_modules() as $module) {
        $tab = trim((string)($module['tab'] ?? ''));
        $page = trim((string)($module['page'] ?? ''));
        if ($tab === '' || $page === '') {
            continue;
        }
        $byTab[$tab] = $module;
    }

    return $byTab;
}

function asr_admin_module_permission_allowed(string $permission): bool {
    $permission = trim($permission) ?: 'admin';

    switch ($permission) {
        case 'public':
            return true;
        case 'results':
            return function_exists('asr_can_view_results') ? asr_can_view_results() : false;
        case 'work_results':
            return function_exists('asr_can_work_results') ? asr_can_work_results() : false;
        case 'admin':
            return function_exists('isAdmin') ? isAdmin() : false;
        default:
            return function_exists('asr_user_has_permission') ? asr_user_has_permission($permission) : false;
    }
}

function asr_admin_module_is_allowed(array $module): bool {
    return asr_admin_module_permission_allowed((string)($module['permission'] ?? 'admin'));
}

function asr_admin_allowed_tabs(): array {
    $tabs = [];
    foreach (asr_admin_modules_by_tab() as $tab => $module) {
        if (asr_admin_module_is_allowed($module)) {
            $tabs[] = $tab;
        }
    }
    return $tabs;
}

function asr_admin_resolve_tab(?string $requestedTab): string {
    $requestedTab = trim((string)$requestedTab);
    $byTab = asr_admin_modules_by_tab();

    if ($requestedTab !== '' && isset($byTab[$requestedTab]) && asr_admin_module_is_allowed($byTab[$requestedTab])) {
        return $requestedTab;
    }

    if (isset($byTab['dashboard']) && asr_admin_module_is_allowed($byTab['dashboard'])) {
        return 'dashboard';
    }

    if (isset($byTab['results']) && asr_admin_module_is_allowed($byTab['results'])) {
        return 'results';
    }

    foreach ($byTab as $tab => $module) {
        if (asr_admin_module_is_allowed($module)) {
            return $tab;
        }
    }

    return 'results';
}

function asr_admin_action_files(): array {
    $files = [];
    foreach (asr_admin_modules() as $module) {
        $actions = $module['actions'] ?? null;
        if (is_string($actions)) {
            $actions = [$actions];
        }
        if (!is_array($actions)) {
            continue;
        }
        foreach ($actions as $file) {
            if (is_string($file) && is_file($file)) {
                $files[] = $file;
            }
        }
    }
    return array_values(array_unique($files));
}

function asr_admin_resolve_page_path(string $currentTab, bool $isDetailView = false, bool $isSharedView = false): ?string {
    $byTab = asr_admin_modules_by_tab();

    if ($isDetailView) {
        $resultsModule = $byTab['results'] ?? null;
        $detailPage = is_array($resultsModule) ? (string)($resultsModule['detail_page'] ?? '') : '';
        if ($detailPage !== '' && is_file($detailPage)) {
            return $detailPage;
        }
        $legacyDetail = dirname(__DIR__) . '/views/pages/detail.php';
        return is_file($legacyDetail) ? $legacyDetail : null;
    }

    if ($isSharedView) {
        return null;
    }

    $module = $byTab[$currentTab] ?? null;
    if (is_array($module) && asr_admin_module_is_allowed($module)) {
        $page = (string)($module['page'] ?? '');
        if ($page !== '' && is_file($page)) {
            return $page;
        }
    }

    $resultsPage = (string)($byTab['results']['page'] ?? '');
    if ($resultsPage !== '' && is_file($resultsPage)) {
        return $resultsPage;
    }

    $legacyResults = dirname(__DIR__) . '/views/pages/results.php';
    return is_file($legacyResults) ? $legacyResults : null;
}
