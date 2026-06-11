<?php
/**
 * SQL-слой модуля редактируемого меню.
 * Здесь только чтение/запись oca_admin_menu_items и дефолтная структура.
 */

defined('ASR_ADMIN') || exit;

function asr_menu_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'oca_admin_menu_items'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}


function asr_default_admin_menu_items(): array {
    return [
        ['id' => -100, 'parent_id' => null, 'title' => 'Общая панель', 'href' => 'admin.php?tab=dashboard', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'can_view_results', 'sort_order' => 5, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'link'],

        ['id' => -1, 'parent_id' => null, 'title' => 'Тесты АСР', 'href' => '', 'item_type' => 'group', 'target' => '_self', 'permission_key' => 'any', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'tests'],
        ['id' => -2, 'parent_id' => -1, 'title' => 'Результаты АСР', 'href' => 'admin.php?tab=results', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'can_view_results', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -3, 'parent_id' => -1, 'title' => 'Спам тесты', 'href' => 'admin.php?spam=blocked', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -4, 'parent_id' => -1, 'title' => 'Ввести вручную', 'href' => 'admin.php?tab=manual_input', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'can_work_results', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -5, 'parent_id' => -1, 'title' => 'Импорт/экспорт', 'href' => 'admin.php?tab=import_export', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 40, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],

        ['id' => -6, 'parent_id' => null, 'title' => 'Маркетинг', 'href' => '', 'item_type' => 'group', 'target' => '_self', 'permission_key' => 'any', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'marketing'],
        ['id' => -7, 'parent_id' => -6, 'title' => 'URL Shortener', 'href' => 'admin.php?tab=url_shortener', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'any', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],

        ['id' => -20, 'parent_id' => null, 'title' => 'Доступы', 'href' => '', 'item_type' => 'group', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 25, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'key'],
        ['id' => -21, 'parent_id' => -20, 'title' => 'Наши сайты', 'href' => 'admin.php?tab=access_vault&category=sites', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -22, 'parent_id' => -20, 'title' => 'Сервисы', 'href' => 'admin.php?tab=access_vault&category=services', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -23, 'parent_id' => -20, 'title' => 'Соц. сети', 'href' => 'admin.php?tab=access_vault&category=social', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -24, 'parent_id' => -20, 'title' => 'Почта', 'href' => 'admin.php?tab=access_vault&category=email', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 40, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -25, 'parent_id' => -20, 'title' => 'Архив', 'href' => 'admin.php?tab=access_vault&category=archive', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.view', 'sort_order' => 50, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -26, 'parent_id' => -20, 'title' => 'Журнал', 'href' => 'admin.php?tab=access_vault&category=audit', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.audit', 'sort_order' => 60, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -27, 'parent_id' => -20, 'title' => 'Импорт/экспорт', 'href' => 'admin.php?tab=access_vault&category=import_export', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'access_vault.import_export', 'sort_order' => 70, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],

        ['id' => -30, 'parent_id' => null, 'title' => 'Telegram-боты', 'href' => '', 'item_type' => 'group', 'target' => '_self', 'permission_key' => 'telegram_bots.view', 'sort_order' => 28, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'telegram'],
        ['id' => -31, 'parent_id' => -30, 'title' => 'Подписчики', 'href' => 'admin.php?tab=telegram_bots&page=subscribers', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'telegram_bots.view', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -32, 'parent_id' => -30, 'title' => 'Диалоги', 'href' => 'admin.php?tab=telegram_bots&page=messages', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'telegram_bots.view', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -33, 'parent_id' => -30, 'title' => 'Рассылки', 'href' => 'admin.php?tab=telegram_bots&page=broadcasts', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'telegram_bots.broadcast', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -34, 'parent_id' => -30, 'title' => 'Сценарии', 'href' => 'admin.php?tab=telegram_bots&page=flows', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'telegram_bots.flows', 'sort_order' => 40, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -35, 'parent_id' => -30, 'title' => 'Каналы', 'href' => 'admin.php?tab=telegram_bots&page=bots', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'telegram_bots.view', 'sort_order' => 50, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],

        ['id' => -40, 'parent_id' => null, 'title' => 'Оргсхемы', 'href' => 'admin.php?tab=org_structure', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 29, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'org'],

        ['id' => -8, 'parent_id' => null, 'title' => 'Настройки', 'href' => '', 'item_type' => 'group', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 30, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'settings'],
        ['id' => -9, 'parent_id' => -8, 'title' => 'Настройки системы', 'href' => 'admin.php?tab=settings', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 10, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
        ['id' => -10, 'parent_id' => -8, 'title' => 'Пользователи', 'href' => 'admin.php?tab=users', 'item_type' => 'internal', 'target' => '_self', 'permission_key' => 'admin', 'sort_order' => 20, 'is_active' => 1, 'is_system' => 1, 'icon_key' => 'dot'],
    ];
}



function asr_menu_sync_system_items(PDO $pdo): void {
    if (!asr_menu_table_exists($pdo)) {
        return;
    }

    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;

    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM oca_admin_menu_items');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
            $columns[(string)$col['Field']] = true;
        }
        foreach (['parent_id','title','href','item_type','target','permission_key','sort_order','is_active','is_system','icon_key','created_at','updated_at'] as $requiredColumn) {
            if (empty($columns[$requiredColumn])) {
                return;
            }
        }

        $defaults = asr_default_admin_menu_items();
        $groups = array_values(array_filter($defaults, static fn(array $item) => ($item['item_type'] ?? '') === 'group'));
        $children = array_values(array_filter($defaults, static fn(array $item) => ($item['item_type'] ?? '') !== 'group'));

        $selectByTitle = $pdo->prepare('SELECT id FROM oca_admin_menu_items WHERE is_system = 1 AND title = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO oca_admin_menu_items (parent_id, title, href, item_type, target, permission_key, sort_order, is_active, is_system, icon_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())');
        $update = $pdo->prepare('UPDATE oca_admin_menu_items SET parent_id = ?, href = ?, item_type = ?, target = ?, permission_key = ?, sort_order = ?, icon_key = ?, updated_at = NOW() WHERE id = ?');

        $idMap = [];
        foreach ($groups as $group) {
            $selectByTitle->execute([$group['title']]);
            $id = (int)$selectByTitle->fetchColumn();
            if ($id <= 0) {
                $insert->execute([null, $group['title'], $group['href'], $group['item_type'], $group['target'], $group['permission_key'], (int)$group['sort_order'], (int)$group['is_active'], $group['icon_key']]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $update->execute([null, $group['href'], $group['item_type'], $group['target'], $group['permission_key'], (int)$group['sort_order'], $group['icon_key'], $id]);
            }
            $idMap[(int)$group['id']] = $id;
        }

        foreach ($children as $child) {
            $parentId = $idMap[(int)$child['parent_id']] ?? null;
            $selectByTitle->execute([$child['title']]);
            $id = (int)$selectByTitle->fetchColumn();
            if ($id <= 0) {
                $insert->execute([$parentId, $child['title'], $child['href'], $child['item_type'], $child['target'], $child['permission_key'], (int)$child['sort_order'], (int)$child['is_active'], $child['icon_key']]);
            } else {
                $update->execute([$parentId, $child['href'], $child['item_type'], $child['target'], $child['permission_key'], (int)$child['sort_order'], $child['icon_key'], $id]);
            }
        }
    } catch (Throwable $e) {
        // Меню не должно валить всю админку. Если таблица отличается от ожидаемой — работаем с тем, что уже есть.
        return;
    }
}

function asr_get_admin_menu_items(PDO $pdo, bool $includeInactive = false): array {
    if (!asr_menu_table_exists($pdo)) {
        return array_values(array_filter(asr_default_admin_menu_items(), static function(array $item) use ($includeInactive) {
            return $includeInactive || (int)$item['is_active'] === 1;
        }));
    }
    asr_menu_sync_system_items($pdo);
    try {
        $sql = "SELECT * FROM oca_admin_menu_items" . ($includeInactive ? "" : " WHERE is_active = 1") . " ORDER BY parent_id IS NOT NULL, sort_order ASC, id ASC";
        $stmt = $pdo->query($sql);
        $items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return $items ?: asr_default_admin_menu_items();
    } catch (Throwable $e) {
        return asr_default_admin_menu_items();
    }
}


function asr_save_menu_items_from_post(PDO $pdo, array $post): void {
    if (!asr_menu_table_exists($pdo)) {
        throw new RuntimeException('Таблица oca_admin_menu_items не найдена. Сначала выполните SQL-миграцию из пакета.');
    }

    $ids = $post['menu_id'] ?? [];
    if (!is_array($ids)) return;

    $stmt = $pdo->prepare("UPDATE oca_admin_menu_items SET title = ?, parent_id = ?, href = ?, item_type = ?, target = ?, permission_key = ?, sort_order = ?, is_active = ?, icon_key = ?, updated_at = NOW() WHERE id = ?");
    foreach ($ids as $i => $rawId) {
        $id = (int)$rawId;
        if ($id <= 0) continue;
        $type = asr_normalize_menu_type((string)($post['menu_item_type'][$i] ?? 'external'));
        $title = asr_normalize_menu_title((string)($post['menu_title'][$i] ?? ''));
        $href = asr_normalize_menu_href((string)($post['menu_href'][$i] ?? ''), $type);
        $parentId = (int)($post['menu_parent_id'][$i] ?? 0);
        if ($parentId === $id) $parentId = 0;
        $target = asr_normalize_menu_target((string)($post['menu_target'][$i] ?? '_self'));
        $permission = asr_normalize_menu_permission((string)($post['menu_permission_key'][$i] ?? 'any'));
        $sortOrder = max(0, min(9999, (int)($post['menu_sort_order'][$i] ?? 100)));
        $isActive = isset($post['menu_is_active'][$id]) ? 1 : 0;
        $iconKey = asr_normalize_menu_icon_key((string)($post['menu_icon_key'][$i] ?? 'link'));
        $stmt->execute([$title, $parentId > 0 ? $parentId : null, $href, $type, $target, $permission, $sortOrder, $isActive, $iconKey, $id]);
    }
}

function asr_create_menu_item_from_post(PDO $pdo, array $post): void {
    if (!asr_menu_table_exists($pdo)) {
        throw new RuntimeException('Таблица oca_admin_menu_items не найдена. Сначала выполните SQL-миграцию из пакета.');
    }
    $type = asr_normalize_menu_type((string)($post['new_menu_item_type'] ?? 'external'));
    $title = asr_normalize_menu_title((string)($post['new_menu_title'] ?? ''));
    $href = asr_normalize_menu_href((string)($post['new_menu_href'] ?? ''), $type);
    $parentId = (int)($post['new_menu_parent_id'] ?? 0);
    $target = asr_normalize_menu_target((string)($post['new_menu_target'] ?? '_self'));
    $permission = asr_normalize_menu_permission((string)($post['new_menu_permission_key'] ?? 'any'));
    $sortOrder = max(0, min(9999, (int)($post['new_menu_sort_order'] ?? 100)));
    $iconKey = asr_normalize_menu_icon_key((string)($post['new_menu_icon_key'] ?? ($type === 'group' ? 'link' : 'dot')));

    $stmt = $pdo->prepare("INSERT INTO oca_admin_menu_items (parent_id, title, href, item_type, target, permission_key, sort_order, is_active, is_system, icon_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW(), NOW())");
    $stmt->execute([$parentId > 0 ? $parentId : null, $title, $href, $type, $target, $permission, $sortOrder, $iconKey]);
}

function asr_delete_menu_item(PDO $pdo, int $id): void {
    if (!asr_menu_table_exists($pdo) || $id <= 0) return;
    $stmt = $pdo->prepare("SELECT is_system FROM oca_admin_menu_items WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    if ((int)($row['is_system'] ?? 0) === 1) {
        throw new RuntimeException('Системный пункт нельзя удалить. Его можно отключить.');
    }
    $pdo->prepare("UPDATE oca_admin_menu_items SET parent_id = NULL WHERE parent_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM oca_admin_menu_items WHERE id = ?")->execute([$id]);
}
