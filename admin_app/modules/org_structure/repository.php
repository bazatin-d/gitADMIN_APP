<?php
defined('ASR_ADMIN') || exit;

function asr_org_structure_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `org_schemes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parent_id` INT UNSIGNED NULL DEFAULT NULL,
        `title` VARCHAR(255) NOT NULL,
        `person_name` VARCHAR(255) NULL DEFAULT NULL,
        `description` TEXT NULL,
        `ckp_text` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `settings_json` TEXT NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_org_schemes_parent` (`parent_id`),
        KEY `idx_org_schemes_status` (`status`),
        KEY `idx_org_schemes_sort` (`sort_order`),
        KEY `idx_org_schemes_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `org_nodes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scheme_id` INT UNSIGNED NOT NULL,
        `parent_id` INT UNSIGNED NULL DEFAULT NULL,
        `type` VARCHAR(30) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `person_name` VARCHAR(255) NULL DEFAULT NULL,
        `description` TEXT NULL,
        `ckp_text` TEXT NULL,
        `color` VARCHAR(20) NULL DEFAULT NULL,
        `managed_by_node_id` INT UNSIGNED NULL DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `head_user_id` INT UNSIGNED NULL DEFAULT NULL,
        `planned_count` DECIMAL(8,2) NULL DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_org_nodes_scheme` (`scheme_id`),
        KEY `idx_org_nodes_parent` (`parent_id`),
        KEY `idx_org_nodes_type` (`type`),
        KEY `idx_org_nodes_sort` (`sort_order`),
        KEY `idx_org_nodes_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    asr_org_structure_add_column_if_missing($pdo, 'org_schemes', 'ckp_text', "ALTER TABLE `org_schemes` ADD COLUMN `ckp_text` TEXT NULL AFTER `description`");
    asr_org_structure_add_column_if_missing($pdo, 'org_nodes', 'person_name', "ALTER TABLE `org_nodes` ADD COLUMN `person_name` VARCHAR(255) NULL DEFAULT NULL AFTER `title`");
    asr_org_structure_add_column_if_missing($pdo, 'org_nodes', 'ckp_text', "ALTER TABLE `org_nodes` ADD COLUMN `ckp_text` TEXT NULL AFTER `description`");
    asr_org_structure_add_column_if_missing($pdo, 'org_nodes', 'color', "ALTER TABLE `org_nodes` ADD COLUMN `color` VARCHAR(20) NULL DEFAULT NULL AFTER `ckp_text`");
    asr_org_structure_add_column_if_missing($pdo, 'org_nodes', 'managed_by_node_id', "ALTER TABLE `org_nodes` ADD COLUMN `managed_by_node_id` INT UNSIGNED NULL DEFAULT NULL AFTER `color`");
}



function asr_org_structure_add_column_if_missing(PDO $pdo, string $table, string $column, string $sql): void {
    if (asr_org_structure_column_exists($pdo, $table, $column)) {
        return;
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        $sqlState = (string)$e->getCode();
        $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;

        // MySQL 1060 = duplicate column. If another request or a previous patch
        // already added the column, the installer must stay idempotent and not
        // break the whole Admin App page.
        if ($sqlState === '42S21' || $driverCode === 1060) {
            return;
        }

        throw $e;
    }
}

function asr_org_structure_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function asr_org_structure_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function asr_org_structure_normalize_status(string $status): string {
    return in_array($status, ['active', 'draft', 'archived'], true) ? $status : 'draft';
}

function asr_org_structure_status_label(string $status): string {
    $labels = ['active' => 'Активная', 'draft' => 'Черновик', 'archived' => 'Архив'];
    return $labels[$status] ?? 'Черновик';
}

function asr_org_structure_node_type_label(string $type): string {
    $labels = [
        'founder' => 'Учредители',
        'top_manager' => 'Топ-менеджмент',
        'deputy' => 'Заместитель',
        'division' => 'Отделение',
        'department' => 'Отдел',
        'section' => 'Секция',
        'position' => 'Должность',
    ];
    return $labels[$type] ?? $type;
}

function asr_org_structure_allowed_node_types(): array {
    return ['founder', 'top_manager', 'deputy', 'division', 'department', 'section', 'position'];
}

function asr_org_structure_normalize_node_type(string $type): string {
    return in_array($type, asr_org_structure_allowed_node_types(), true) ? $type : 'division';
}


function asr_org_structure_normalize_color(?string $color): ?string {
    $color = trim((string)$color);
    if ($color === '') return null;
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtoupper($color);
    }
    return null;
}

function asr_org_structure_normalize_person_name(?string $personName): string {
    $personName = trim((string)$personName);
    if ($personName === '') return '';
    return trim((string)preg_replace('/\s+/u', ' ', $personName));
}

function asr_org_structure_create_division_with_departments(PDO $pdo, array $data): int {
    asr_org_structure_ensure_schema($pdo);
    $departmentCount = max(0, min(40, (int)($data['department_count'] ?? 0)));
    $divisionId = asr_org_structure_create_node($pdo, array_merge($data, [
        'type' => 'division',
        'parent_id' => 0,
    ]));

    for ($i = 1; $i <= $departmentCount; $i++) {
        asr_org_structure_create_node($pdo, [
            'scheme_id' => (int)$data['scheme_id'],
            'parent_id' => $divisionId,
            'type' => 'department',
            'title' => 'Отдел ' . $i,
            'description' => '',
            'sort_order' => $i * 10,
        ]);
    }

    return $divisionId;
}

function asr_org_structure_set_manager_divisions(PDO $pdo, int $schemeId, int $managerId, array $divisionIds): void {
    asr_org_structure_ensure_schema($pdo);
    if ($schemeId <= 0 || $managerId <= 0) return;

    $ids = array_values(array_unique(array_filter(array_map('intval', $divisionIds), static fn(int $id) => $id > 0)));

    $pdo->prepare("UPDATE org_nodes SET managed_by_node_id = NULL, updated_at = NOW() WHERE scheme_id = ? AND type = 'division' AND deleted_at IS NULL AND managed_by_node_id = ?")
        ->execute([$schemeId, $managerId]);

    if (!$ids) return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$managerId, $schemeId], $ids);
    $stmt = $pdo->prepare("UPDATE org_nodes SET managed_by_node_id = ?, updated_at = NOW() WHERE scheme_id = ? AND type = 'division' AND deleted_at IS NULL AND managed_by_node_id IS NULL AND id IN ($placeholders)");
    $stmt->execute($params);
}

function asr_org_structure_assign_divisions_to_manager(PDO $pdo, int $schemeId, int $managerId, array $divisionIds): void {
    asr_org_structure_set_manager_divisions($pdo, $schemeId, $managerId, $divisionIds);
}

function asr_org_structure_create_director_child(PDO $pdo, array $data): int {
    asr_org_structure_ensure_schema($pdo);
    $schemeId = (int)($data['scheme_id'] ?? 0);
    $parentId = (int)($data['parent_id'] ?? 0);
    if ($schemeId <= 0 || $parentId <= 0) {
        throw new RuntimeException('Исполнительный директор не найден.');
    }

    $kind = (string)($data['create_kind'] ?? 'division');
    if ($kind === 'top_manager') {
        $managerId = asr_org_structure_create_node($pdo, [
            'scheme_id' => $schemeId,
            'parent_id' => $parentId,
            'type' => 'top_manager',
            'title' => (string)($data['top_title'] ?? ''),
            'person_name' => (string)($data['top_person_name'] ?? ''),
            'description' => (string)($data['top_description'] ?? ''),
            'ckp_text' => (string)($data['top_ckp_text'] ?? ''),
            'color' => (string)($data['top_color'] ?? ''),
            'sort_order' => (int)($data['top_sort_order'] ?? 100),
        ]);
        asr_org_structure_assign_divisions_to_manager($pdo, $schemeId, $managerId, (array)($data['division_ids'] ?? []));
        return $managerId;
    }

    return asr_org_structure_create_division_with_departments($pdo, [
        'scheme_id' => $schemeId,
        'parent_id' => 0,
        'type' => 'division',
        'title' => (string)($data['division_title'] ?? ''),
        'person_name' => '',
        'description' => (string)($data['division_description'] ?? ''),
        'ckp_text' => (string)($data['division_ckp_text'] ?? ''),
        'color' => (string)($data['division_color'] ?? ''),
        'sort_order' => (int)($data['division_sort_order'] ?? 100),
        'department_count' => (int)($data['department_count'] ?? 0),
    ]);
}

function asr_org_structure_fetch_schemes(PDO $pdo, bool $includeArchived = false): array {
    asr_org_structure_ensure_schema($pdo);
    $sql = "
        SELECT s.*,
               (SELECT COUNT(*) FROM org_nodes n WHERE n.scheme_id = s.id AND n.deleted_at IS NULL AND n.type = 'division') AS divisions_count,
               (SELECT COUNT(*) FROM org_nodes n WHERE n.scheme_id = s.id AND n.deleted_at IS NULL AND n.type = 'department') AS departments_count,
               (SELECT COUNT(*) FROM org_nodes n WHERE n.scheme_id = s.id AND n.deleted_at IS NULL AND n.type = 'position') AS positions_count,
               (SELECT COUNT(*) FROM org_schemes c WHERE c.parent_id = s.id AND c.deleted_at IS NULL) AS children_count
        FROM org_schemes s
        WHERE s.deleted_at IS NULL
    ";
    if (!$includeArchived) {
        $sql .= " AND s.status <> 'archived'";
    }
    $sql .= " ORDER BY COALESCE(s.parent_id, 0) ASC, s.sort_order ASC, s.id ASC";
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_org_structure_fetch_scheme(PDO $pdo, int $id): ?array {
    asr_org_structure_ensure_schema($pdo);
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT * FROM org_schemes WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_org_structure_fetch_nodes(PDO $pdo, int $schemeId): array {
    asr_org_structure_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM org_nodes WHERE scheme_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$schemeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function asr_org_structure_ensure_default_management_nodes(PDO $pdo, int $schemeId): void {
    asr_org_structure_ensure_schema($pdo);
    if ($schemeId <= 0) return;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM org_nodes WHERE scheme_id = ? AND type = 'founder' AND deleted_at IS NULL");
    $stmt->execute([$schemeId]);
    if ((int)$stmt->fetchColumn() === 0) {
        asr_org_structure_create_node($pdo, [
            'scheme_id' => $schemeId,
            'parent_id' => 0,
            'type' => 'founder',
            'title' => 'Учредитель / Совет учредителей',
            'description' => '',
            'sort_order' => 5,
        ]);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM org_nodes WHERE scheme_id = ? AND type = 'top_manager' AND deleted_at IS NULL");
    $stmt->execute([$schemeId]);
    if ((int)$stmt->fetchColumn() === 0) {
        asr_org_structure_create_node($pdo, [
            'scheme_id' => $schemeId,
            'parent_id' => 0,
            'type' => 'top_manager',
            'title' => 'Исполнительный директор',
            'description' => '',
            'sort_order' => 10,
        ]);
    }
}


function asr_org_structure_cleanup_extra_top_managers(PDO $pdo, int $schemeId): void {
    asr_org_structure_ensure_schema($pdo);
    if ($schemeId <= 0) return;

    $stmt = $pdo->prepare("SELECT id FROM org_nodes WHERE scheme_id = ? AND type = 'top_manager' AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$schemeId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (count($ids) <= 1) return;

    // На текущем этапе в шаблоне должен быть только один верхний блок —
    // исполнительный директор. Добавление остальных топ-менеджеров будет
    // сделано отдельным ТЗ, чтобы не плодить блоки на неверном уровне.
    foreach (array_slice($ids, 1) as $extraId) {
        asr_org_structure_delete_node($pdo, $extraId, $schemeId);
    }
}

function asr_org_structure_nodes_by_parent(array $nodes): array {
    $map = [];
    foreach ($nodes as $node) {
        $parentId = $node['parent_id'] === null ? 0 : (int)$node['parent_id'];
        $map[$parentId][] = $node;
    }
    return $map;
}

function asr_org_structure_create_scheme(PDO $pdo, array $data): int {
    asr_org_structure_ensure_schema($pdo);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Укажите название оргсхемы.');
    }
    if (mb_strlen($title, 'UTF-8') > 255) {
        throw new RuntimeException('Название оргсхемы слишком длинное.');
    }

    $parentId = (int)($data['parent_id'] ?? 0);
    $description = trim((string)($data['description'] ?? ''));
    $ckpText = trim((string)($data['ckp_text'] ?? ''));
    $status = asr_org_structure_normalize_status((string)($data['status'] ?? 'draft'));
    $divisionCount = max(0, min(30, (int)($data['division_count'] ?? 0)));
    $departmentCount = max(0, min(12, (int)($data['department_count'] ?? 0)));
    $settings = json_encode([
        'department_count' => $departmentCount,
        'sections_available' => 1,
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO org_schemes (parent_id, title, description, ckp_text, status, settings_json, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 100, NOW(), NOW())");
    $stmt->execute([$parentId > 0 ? $parentId : null, $title, $description !== '' ? $description : null, $ckpText !== '' ? $ckpText : null, $status, $settings]);
    $schemeId = (int)$pdo->lastInsertId();

    asr_org_structure_create_node($pdo, [
        'scheme_id' => $schemeId,
        'parent_id' => 0,
        'type' => 'founder',
        'title' => 'Учредитель / Совет учредителей',
        'description' => '',
        'sort_order' => 5,
    ]);

    asr_org_structure_create_node($pdo, [
        'scheme_id' => $schemeId,
        'parent_id' => 0,
        'type' => 'top_manager',
        'title' => 'Исполнительный директор',
        'description' => '',
        'sort_order' => 10,
    ]);

    for ($i = 1; $i <= $divisionCount; $i++) {
        $divisionId = asr_org_structure_create_node($pdo, [
            'scheme_id' => $schemeId,
            'parent_id' => 0,
            'type' => 'division',
            'title' => 'Отделение ' . $i,
            'description' => '',
            'sort_order' => $i * 10,
        ]);
        if ($departmentCount > 0) {
            for ($d = 1; $d <= $departmentCount; $d++) {
                asr_org_structure_create_node($pdo, [
                    'scheme_id' => $schemeId,
                    'parent_id' => $divisionId,
                    'type' => 'department',
                    'title' => 'Отдел ' . $d,
                    'description' => '',
                    'sort_order' => $d * 10,
                ]);
            }
        }
    }

    return $schemeId;
}

function asr_org_structure_update_scheme(PDO $pdo, int $id, array $data): void {
    asr_org_structure_ensure_schema($pdo);
    if ($id <= 0) throw new RuntimeException('Оргсхема не найдена.');
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') throw new RuntimeException('Укажите название оргсхемы.');
    $parentId = (int)($data['parent_id'] ?? 0);
    if ($parentId === $id) $parentId = 0;
    $status = asr_org_structure_normalize_status((string)($data['status'] ?? 'draft'));
    $description = trim((string)($data['description'] ?? ''));
    $ckpText = trim((string)($data['ckp_text'] ?? ''));
    $stmt = $pdo->prepare("UPDATE org_schemes SET parent_id = ?, title = ?, description = ?, ckp_text = ?, status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$parentId > 0 ? $parentId : null, $title, $description !== '' ? $description : null, $ckpText !== '' ? $ckpText : null, $status, $id]);
}

function asr_org_structure_archive_scheme(PDO $pdo, int $id): void {
    asr_org_structure_ensure_schema($pdo);
    if ($id <= 0) return;
    $stmt = $pdo->prepare("UPDATE org_schemes SET status = 'archived', deleted_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
}

function asr_org_structure_create_node(PDO $pdo, array $data): int {
    asr_org_structure_ensure_schema($pdo);
    $schemeId = (int)($data['scheme_id'] ?? 0);
    if ($schemeId <= 0) throw new RuntimeException('Оргсхема не найдена.');
    $type = asr_org_structure_normalize_node_type((string)($data['type'] ?? 'division'));
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') throw new RuntimeException('Укажите название элемента.');
    $parentId = (int)($data['parent_id'] ?? 0);
    $personName = asr_org_structure_normalize_person_name((string)($data['person_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $ckpText = trim((string)($data['ckp_text'] ?? ''));
    $color = asr_org_structure_normalize_color((string)($data['color'] ?? ''));
    if ($type === 'deputy') { $color = null; }
    $sortOrder = (int)($data['sort_order'] ?? 100);
    $plannedCountRaw = trim((string)($data['planned_count'] ?? ''));
    $plannedCount = $plannedCountRaw !== '' ? (float)str_replace(',', '.', $plannedCountRaw) : null;

    $stmt = $pdo->prepare("INSERT INTO org_nodes (scheme_id, parent_id, type, title, person_name, description, ckp_text, color, sort_order, planned_count, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
    $stmt->execute([$schemeId, $parentId > 0 ? $parentId : null, $type, $title, $personName !== '' ? $personName : null, $description !== '' ? $description : null, $ckpText !== '' ? $ckpText : null, $color, $sortOrder, $plannedCount]);
    return (int)$pdo->lastInsertId();
}

function asr_org_structure_update_node(PDO $pdo, int $id, array $data): void {
    asr_org_structure_ensure_schema($pdo);
    if ($id <= 0) throw new RuntimeException('Элемент не найден.');
    $schemeId = (int)($data['scheme_id'] ?? 0);
    $type = asr_org_structure_normalize_node_type((string)($data['type'] ?? 'division'));
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') throw new RuntimeException('Укажите название элемента.');
    $parentId = (int)($data['parent_id'] ?? 0);
    if ($parentId === $id) $parentId = 0;
    $personName = asr_org_structure_normalize_person_name((string)($data['person_name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $ckpText = trim((string)($data['ckp_text'] ?? ''));
    $color = asr_org_structure_normalize_color((string)($data['color'] ?? ''));
    if ($type === 'deputy') { $color = null; }
    $sortOrder = (int)($data['sort_order'] ?? 100);
    $plannedCountRaw = trim((string)($data['planned_count'] ?? ''));
    $plannedCount = $plannedCountRaw !== '' ? (float)str_replace(',', '.', $plannedCountRaw) : null;

    $stmt = $pdo->prepare("UPDATE org_nodes SET parent_id = ?, type = ?, title = ?, person_name = ?, description = ?, ckp_text = ?, color = ?, sort_order = ?, planned_count = ?, updated_at = NOW() WHERE id = ? AND scheme_id = ? AND deleted_at IS NULL");
    $stmt->execute([$parentId > 0 ? $parentId : null, $type, $title, $personName !== '' ? $personName : null, $description !== '' ? $description : null, $ckpText !== '' ? $ckpText : null, $color, $sortOrder, $plannedCount, $id, $schemeId]);

    if ($type === 'top_manager' && array_key_exists('managed_division_ids', $data)) {
        asr_org_structure_set_manager_divisions($pdo, $schemeId, $id, (array)$data['managed_division_ids']);
    }
}

function asr_org_structure_delete_node(PDO $pdo, int $id, int $schemeId): void {
    asr_org_structure_ensure_schema($pdo);
    if ($id <= 0 || $schemeId <= 0) return;

    // Если удаляем топ-руководителя, отделения не удаляются — только снимается
    // закрепление подчинения, чтобы их можно было выбрать у другого руководителя.
    $pdo->prepare("UPDATE org_nodes SET managed_by_node_id = NULL, updated_at = NOW() WHERE scheme_id = ? AND type = 'division' AND deleted_at IS NULL AND managed_by_node_id = ?")
        ->execute([$schemeId, $id]);

    $ids = [$id];
    $queue = [$id];
    while ($queue) {
        $parent = array_shift($queue);
        $stmt = $pdo->prepare("SELECT id FROM org_nodes WHERE scheme_id = ? AND parent_id = ? AND deleted_at IS NULL");
        $stmt->execute([$schemeId, $parent]);
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $childId) {
            $childId = (int)$childId;
            $ids[] = $childId;
            $queue[] = $childId;
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$schemeId], $ids);
    $pdo->prepare("UPDATE org_nodes SET deleted_at = NOW(), updated_at = NOW() WHERE scheme_id = ? AND id IN ($placeholders)")->execute($params);
}
