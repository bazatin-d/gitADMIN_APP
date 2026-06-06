<?php
defined('ASR_ADMIN') || exit;

function asr_av_table_exists(PDO $pdo, string $table): bool {
    try { $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table)); return (bool)$stmt->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function asr_av_schema_ready(PDO $pdo): bool {
    return asr_av_table_exists($pdo, 'oca_access_resources') && asr_av_table_exists($pdo, 'oca_access_credentials') && asr_av_table_exists($pdo, 'oca_access_groups') && asr_av_table_exists($pdo, 'oca_access_audit_log');
}

function asr_av_ensure_group_style_columns(PDO $pdo): void {
    if (!asr_av_table_exists($pdo, 'oca_access_groups')) return;
    $columnExists = function (string $column) use ($pdo): bool {
        if (function_exists('asr_table_column_exists')) {
            try { return (bool)asr_table_column_exists($pdo, 'oca_access_groups', $column); } catch (Throwable $e) {}
        }
        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `oca_access_groups` LIKE ?');
            $stmt->execute([$column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return false; }
    };
    $columns = [
        'color' => "ALTER TABLE `oca_access_groups` ADD COLUMN `color` VARCHAR(7) NULL DEFAULT '#F4E4A6' AFTER `sort_order`",
        'text_color' => "ALTER TABLE `oca_access_groups` ADD COLUMN `text_color` VARCHAR(7) NULL DEFAULT '#4B5563' AFTER `color`",
        'icon_key' => "ALTER TABLE `oca_access_groups` ADD COLUMN `icon_key` VARCHAR(40) NULL DEFAULT 'flask' AFTER `text_color`",
    ];
    foreach ($columns as $column => $sql) {
        if (!$columnExists($column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) { /* не роняем страницу */ }
        }
    }
}

function asr_av_group_column_exists(PDO $pdo, string $column): bool {
    if (function_exists('asr_table_column_exists')) {
        try { return (bool)asr_table_column_exists($pdo, 'oca_access_groups', $column); } catch (Throwable $e) {}
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `oca_access_groups` LIKE ?');
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}

function asr_av_group_sql_fields(PDO $pdo): string {
    asr_av_ensure_group_style_columns($pdo);
    $hasColor = asr_av_group_column_exists($pdo, 'color');
    $hasTextColor = asr_av_group_column_exists($pdo, 'text_color');
    $hasIcon = asr_av_group_column_exists($pdo, 'icon_key');
    return ', ' . ($hasColor ? 'g.color' : "'#F4E4A6'") . ' AS group_color'
        . ', ' . ($hasTextColor ? 'g.text_color' : "'#4B5563'") . ' AS group_text_color'
        . ', ' . ($hasIcon ? 'g.icon_key' : "'flask'") . ' AS group_icon';
}

function asr_av_normalize_hex_color(string $value, string $fallback): string {
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
}

function asr_av_get_users_for_share(PDO $pdo): array {
    $where = function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'is_active') ? 'WHERE is_active = 1' : '';
    return $pdo->query("SELECT id, full_name, username, role FROM oca_users {$where} ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function asr_av_categories(): array {
    return [
        'sites' => 'Наши сайты',
        'services' => 'Сервисы',
        'social' => 'Соц. сети',
        'email' => 'Почта',
        'archive' => 'Архив',
        'audit' => 'Журнал',
        'import_export' => 'Импорт/экспорт',
    ];
}

function asr_av_normalize_category(string $category): string {
    $category = trim($category);
    return in_array($category, ['sites','services','social','email','archive','audit','import_export'], true) ? $category : 'sites';
}

function asr_av_ensure_credential_sort_order(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $exists = function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_access_credentials', 'sort_order')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_access_credentials', 'sort_order'));
    if (!$exists) {
        try { $pdo->exec('ALTER TABLE `oca_access_credentials` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 100 AFTER `status`'); } catch (Throwable $e) {}
    }
}

function asr_av_find_group(PDO $pdo, int $groupId): ?array {
    if ($groupId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM oca_access_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_av_groups(PDO $pdo, string $category): array {
    asr_av_ensure_group_style_columns($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_access_groups WHERE category = ? ORDER BY sort_order ASC, title ASC');
    $stmt->execute([$category]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function asr_av_get_or_create_group(PDO $pdo, string $category, string $title, int $userId = 0): int {
    asr_av_ensure_group_style_columns($pdo);
    $title = trim($title) ?: 'Без группы';
    $stmt = $pdo->prepare('SELECT id FROM oca_access_groups WHERE category = ? AND title = ? LIMIT 1');
    $stmt->execute([$category, $title]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;
    $stmt = $pdo->prepare('INSERT INTO oca_access_groups (category, title, sort_order, color, text_color, icon_key, created_by, updated_by, created_at, updated_at) VALUES (?, ?, 100, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$category, $title, '#F4E4A6', '#4B5563', 'flask', $userId ?: null, $userId ?: null]);
    return (int)$pdo->lastInsertId();
}

function asr_av_create_group(PDO $pdo, string $category, string $title, int $userId, int $sortOrder = 100, string $color = '#F4E4A6', string $textColor = '#4B5563', string $iconKey = 'flask'): int {
    asr_av_ensure_group_style_columns($pdo);
    $sortOrder = max(0, min(9999, $sortOrder));
    $color = asr_av_normalize_hex_color($color, '#F4E4A6');
    $textColor = asr_av_normalize_hex_color($textColor, '#4B5563');
    $iconKey = preg_match('/^[a-z0-9_-]{1,40}$/i', $iconKey) ? $iconKey : 'flask';
    $stmt = $pdo->prepare('INSERT INTO oca_access_groups (category, title, sort_order, color, text_color, icon_key, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$category, $title, $sortOrder, $color, $textColor, $iconKey, $userId, $userId]);
    return (int)$pdo->lastInsertId();
}

function asr_av_update_group(PDO $pdo, int $groupId, string $title, int $userId, ?int $sortOrder = null, ?string $color = null, ?string $textColor = null, ?string $iconKey = null): void {
    asr_av_ensure_group_style_columns($pdo);
    $sets = ['title = ?', 'updated_by = ?', 'updated_at = NOW()'];
    $params = [$title, $userId];
    if ($sortOrder !== null) { $sets[] = 'sort_order = ?'; $params[] = max(0, min(9999, $sortOrder)); }
    if ($color !== null) { $sets[] = 'color = ?'; $params[] = asr_av_normalize_hex_color($color, '#F4E4A6'); }
    if ($textColor !== null) { $sets[] = 'text_color = ?'; $params[] = asr_av_normalize_hex_color($textColor, '#4B5563'); }
    if ($iconKey !== null) { $sets[] = 'icon_key = ?'; $params[] = preg_match('/^[a-z0-9_-]{1,40}$/i', $iconKey) ? $iconKey : 'flask'; }
    $params[] = $groupId;
    $stmt = $pdo->prepare('UPDATE oca_access_groups SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

function asr_av_delete_group(PDO $pdo, int $groupId): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oca_access_resources WHERE group_id = ? AND status = "active"');
    $stmt->execute([$groupId]);
    if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('В группе есть активные ресурсы. Сначала перенесите или архивируйте их.');
    $pdo->prepare('UPDATE oca_access_resources SET group_id = NULL WHERE group_id = ?')->execute([$groupId]);
    $pdo->prepare('DELETE FROM oca_access_groups WHERE id = ?')->execute([$groupId]);
}

function asr_av_resources(PDO $pdo, string $category, string $search = ''): array {
    $params = [];
    $where = [];
    if ($category === 'archive') {
        $where[] = '(r.status = "archived" OR EXISTS (SELECT 1 FROM oca_access_credentials ac WHERE ac.resource_id = r.id AND ac.status = "archived"))';
    } else {
        $where[] = 'r.category = ?'; $params[] = $category;
        $where[] = 'r.status = "active"';
    }
    if ($search !== '') {
        $where[] = '(r.title LIKE ? OR r.url LIKE ? OR r.comment LIKE ? OR g.title LIKE ? OR EXISTS (SELECT 1 FROM oca_access_credentials c WHERE c.resource_id = r.id AND (c.login LIKE ? OR c.comment LIKE ?)))';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT r.*, g.title AS group_title' . asr_av_group_sql_fields($pdo) . ' FROM oca_access_resources r LEFT JOIN oca_access_groups g ON g.id = r.group_id WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(g.sort_order,9999), COALESCE(g.title,"Без группы"), r.title ASC, r.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function asr_av_find_resource(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT r.*, g.title AS group_title' . asr_av_group_sql_fields($pdo) . ' FROM oca_access_resources r LEFT JOIN oca_access_groups g ON g.id = r.group_id WHERE r.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_av_create_resource(PDO $pdo, array $data, int $userId): int {
    $stmt = $pdo->prepare('INSERT INTO oca_access_resources (category, group_id, title, url, comment, status, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "active", ?, ?, NOW(), NOW())');
    $stmt->execute([$data['category'], $data['group_id'] ?: null, $data['title'], $data['url'], $data['comment'], $userId, $userId]);
    return (int)$pdo->lastInsertId();
}

function asr_av_update_resource(PDO $pdo, int $id, array $data, int $userId): void {
    $stmt = $pdo->prepare('UPDATE oca_access_resources SET category = ?, group_id = ?, title = ?, url = ?, comment = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$data['category'], $data['group_id'] ?: null, $data['title'], $data['url'], $data['comment'], $userId, $id]);
}

function asr_av_update_resource_group(PDO $pdo, int $resourceId, ?int $groupId, int $userId): void {
    $resourceId = max(0, $resourceId);
    if ($resourceId <= 0) {
        throw new RuntimeException('Ресурс не найден.');
    }

    $stmt = $pdo->prepare('SELECT id, category FROM oca_access_resources WHERE id = ? LIMIT 1');
    $stmt->execute([$resourceId]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resource) {
        throw new RuntimeException('Ресурс не найден.');
    }

    $groupId = $groupId ? max(0, (int)$groupId) : 0;
    if ($groupId > 0) {
        $g = asr_av_find_group($pdo, $groupId);
        if (!$g) {
            throw new RuntimeException('Группа не найдена.');
        }
        if ((string)($g['category'] ?? '') !== (string)$resource['category']) {
            throw new RuntimeException('Выбранная группа относится к другому разделу.');
        }
    }

    $stmt = $pdo->prepare('UPDATE oca_access_resources SET group_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$groupId > 0 ? $groupId : null, $userId, $resourceId]);
}

function asr_av_delete_credential_permanent(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    if (asr_av_table_exists($pdo, 'oca_access_credential_assignees')) {
        $pdo->prepare('DELETE FROM oca_access_credential_assignees WHERE credential_id = ?')->execute([$id]);
    }
    if (asr_av_table_exists($pdo, 'oca_access_credential_users')) {
        $pdo->prepare('DELETE FROM oca_access_credential_users WHERE credential_id = ?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM oca_access_credentials WHERE id = ?')->execute([$id]);
}

function asr_av_delete_resource_permanent(PDO $pdo, int $id): void {
    if ($id <= 0) return;
    $stmt = $pdo->prepare('SELECT id FROM oca_access_credentials WHERE resource_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $credentialId) {
        asr_av_delete_credential_permanent($pdo, (int)$credentialId);
    }
    if (asr_av_table_exists($pdo, 'oca_access_resource_payments')) {
        $stmt = $pdo->prepare('SELECT id FROM oca_access_resource_payments WHERE resource_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
            if (asr_av_table_exists($pdo, 'oca_access_payment_recipients')) $pdo->prepare('DELETE FROM oca_access_payment_recipients WHERE payment_id = ?')->execute([(int)$paymentId]);
            if (asr_av_table_exists($pdo, 'oca_access_payment_notifications')) $pdo->prepare('DELETE FROM oca_access_payment_notifications WHERE payment_id = ?')->execute([(int)$paymentId]);
        }
        $pdo->prepare('DELETE FROM oca_access_resource_payments WHERE resource_id = ?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM oca_access_resources WHERE id = ?')->execute([$id]);
}

function asr_av_set_resource_status(PDO $pdo, int $id, string $status, int $userId): void {
    if ($status === 'archived') {
        $pdo->prepare('UPDATE oca_access_resources SET status="archived", archived_by=?, archived_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')->execute([$userId, $userId, $id]);
        $pdo->prepare('UPDATE oca_access_credentials SET status="archived", archived_by=?, archived_at=NOW(), updated_by=?, updated_at=NOW() WHERE resource_id=?')->execute([$userId, $userId, $id]);
    } else {
        $pdo->prepare('UPDATE oca_access_resources SET status="active", archived_by=NULL, archived_at=NULL, updated_by=?, updated_at=NOW() WHERE id=?')->execute([$userId, $id]);
    }
}

function asr_av_credentials_for_resources(PDO $pdo, array $resourceIds, bool $includeArchived = false): array {
    asr_av_ensure_credential_sort_order($pdo);
    $resourceIds = array_values(array_filter(array_map('intval', $resourceIds)));
    if (!$resourceIds) return [];
    $in = implode(',', array_fill(0, count($resourceIds), '?'));
    $where = 'resource_id IN (' . $in . ')';
    $params = $resourceIds;
    if (!$includeArchived) $where .= ' AND status = "active"';

    // Индивидуальная видимость: если у конкретного доступа указаны сотрудники,
    // обычный пользователь увидит его только если он в списке. Администратор видит всё.
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $isAdmin = function_exists('isAdmin') && isAdmin();
    if (!$isAdmin && $userId > 0 && asr_av_individual_table_ready($pdo)) {
        $where .= ' AND (NOT EXISTS (SELECT 1 FROM oca_access_credential_users acu WHERE acu.credential_id = oca_access_credentials.id) OR EXISTS (SELECT 1 FROM oca_access_credential_users acu2 WHERE acu2.credential_id = oca_access_credentials.id AND acu2.user_id = ?))';
        $params[] = $userId;
    }

    $stmt = $pdo->prepare('SELECT * FROM oca_access_credentials WHERE ' . $where . ' ORDER BY resource_id ASC, sort_order ASC, login ASC, id ASC');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['resource_id']][] = $row;
    return $out;
}

function asr_av_find_credential(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT c.*, r.title AS resource_title, r.url AS resource_url, r.category AS resource_category FROM oca_access_credentials c JOIN oca_access_resources r ON r.id=c.resource_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_av_create_credential(PDO $pdo, int $resourceId, string $login, string $passwordEncrypted, string $comment, int $userId): int {
    asr_av_ensure_credential_sort_order($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_access_credentials WHERE resource_id = ?');
    $stmt->execute([$resourceId]);
    $sortOrder = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO oca_access_credentials (resource_id, login, password_encrypted, comment, status, sort_order, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, "active", ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$resourceId, $login, $passwordEncrypted, $comment, $sortOrder ?: 100, $userId, $userId]);
    return (int)$pdo->lastInsertId();
}

function asr_av_update_credential(PDO $pdo, int $id, string $login, ?string $passwordEncrypted, string $comment, int $userId, ?int $resourceId = null): void {
    asr_av_ensure_credential_sort_order($pdo);
    $sets = ['login = ?', 'comment = ?', 'updated_by = ?', 'updated_at = NOW()'];
    $params = [$login, $comment, $userId];
    if ($passwordEncrypted !== null) {
        $sets[] = 'password_encrypted = ?';
        $params[] = $passwordEncrypted;
    }
    if ($resourceId !== null && $resourceId > 0) {
        $sets[] = 'resource_id = ?';
        $params[] = $resourceId;
    }
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE oca_access_credentials SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

function asr_av_reorder_credentials(PDO $pdo, int $resourceId, array $ids, int $userId = 0): void {
    asr_av_ensure_credential_sort_order($pdo);
    $resourceId = max(0, $resourceId);
    if ($resourceId <= 0 || !$ids) return;
    $stmt = $pdo->prepare('UPDATE oca_access_credentials SET sort_order = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND resource_id = ?');
    $order = 100;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $stmt->execute([$order, $userId ?: null, $id, $resourceId]);
        $order += 100;
    }
}

function asr_av_set_credential_status(PDO $pdo, int $id, string $status, int $userId): void {
    if ($status === 'archived') {
        $pdo->prepare('UPDATE oca_access_credentials SET status="archived", archived_by=?, archived_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')->execute([$userId, $userId, $id]);
    } else {
        $pdo->prepare('UPDATE oca_access_credentials SET status="active", archived_by=NULL, archived_at=NULL, updated_by=?, updated_at=NOW() WHERE id=?')->execute([$userId, $id]);
    }
}

function asr_av_audit(PDO $pdo, string $action, ?int $resourceId = null, ?int $credentialId = null, array $details = []): void {
    if (!asr_av_table_exists($pdo, 'oca_access_audit_log')) return;
    $userId = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt = $pdo->prepare('INSERT INTO oca_access_audit_log (user_id, action, resource_id, credential_id, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $action, $resourceId, $credentialId, $json, $ip, $ua]);

    // Telegram-оповещения о событиях доступа не должны ломать основное действие.
    // Поэтому любые ошибки отправки проглатываем: аудит уже сохранён, пользователь не должен получить fatal error.
    if (function_exists('asr_av_access_event_telegram_notify')) {
        try {
            asr_av_access_event_telegram_notify($pdo, $action, $resourceId, $credentialId, $details, $userId);
        } catch (Throwable $e) {
            // noop
        }
    }
}

function asr_av_audit_rows(PDO $pdo, int $limit = 100): array {
    $limit = max(10, min(500, $limit));
    $sql = 'SELECT a.*, u.full_name, u.username, r.title AS resource_title, r.category AS resource_category, c.login AS credential_login FROM oca_access_audit_log a LEFT JOIN oca_users u ON u.id=a.user_id LEFT JOIN oca_access_resources r ON r.id=a.resource_id LEFT JOIN oca_access_credentials c ON c.id=a.credential_id ORDER BY a.id DESC LIMIT ' . $limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}


/* -------------------------------------------------------------------------
 * Дополнительные функции: индивидуальная видимость доступов и оплата ресурсов.
 * Добавлены мягко: если таблиц/колонок нет, интерфейс не падает.
 * ------------------------------------------------------------------------- */

if (!function_exists('asr_table_column_exists')) {
function asr_table_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}
}

function asr_av_individual_table_ready(PDO $pdo): bool {
    if (!asr_av_table_exists($pdo, 'oca_access_credential_users')) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS oca_access_credential_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                credential_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_credential_user (credential_id, user_id),
                KEY idx_user_id (user_id),
                KEY idx_credential_id (credential_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {}
    }
    return asr_av_table_exists($pdo, 'oca_access_credential_users');
}

function asr_av_credential_allowed_user_ids(PDO $pdo, int $credentialId): array {
    if ($credentialId <= 0 || !asr_av_individual_table_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT user_id FROM oca_access_credential_users WHERE credential_id = ? ORDER BY user_id ASC');
    $stmt->execute([$credentialId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function asr_av_save_credential_allowed_users(PDO $pdo, int $credentialId, array $userIds): void {
    if ($credentialId <= 0 || !asr_av_individual_table_ready($pdo)) return;
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
    $pdo->prepare('DELETE FROM oca_access_credential_users WHERE credential_id = ?')->execute([$credentialId]);
    if (!$userIds) return;
    $stmt = $pdo->prepare('INSERT IGNORE INTO oca_access_credential_users (credential_id, user_id, created_at) VALUES (?, ?, NOW())');
    foreach ($userIds as $uid) $stmt->execute([$credentialId, $uid]);
}


function asr_av_assigned_table_ready(PDO $pdo): bool {
    if (!asr_av_table_exists($pdo, 'oca_access_credential_assignees')) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS oca_access_credential_assignees (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                credential_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_credential_assignee (credential_id, user_id),
                KEY idx_user_id (user_id),
                KEY idx_credential_id (credential_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {}
    }
    return asr_av_table_exists($pdo, 'oca_access_credential_assignees');
}

function asr_av_credential_assigned_user_ids(PDO $pdo, int $credentialId): array {
    if ($credentialId <= 0 || !asr_av_assigned_table_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT user_id FROM oca_access_credential_assignees WHERE credential_id = ? ORDER BY user_id ASC');
    $stmt->execute([$credentialId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function asr_av_save_credential_assigned_users(PDO $pdo, int $credentialId, array $userIds): void {
    if ($credentialId <= 0 || !asr_av_assigned_table_ready($pdo)) return;
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
    $pdo->prepare('DELETE FROM oca_access_credential_assignees WHERE credential_id = ?')->execute([$credentialId]);
    if (!$userIds) return;
    $stmt = $pdo->prepare('INSERT IGNORE INTO oca_access_credential_assignees (credential_id, user_id, created_at) VALUES (?, ?, NOW())');
    foreach ($userIds as $uid) $stmt->execute([$credentialId, $uid]);
}

function asr_av_remove_user_from_access_links(PDO $pdo, int $userId): void {
    if ($userId <= 0) return;
    if (asr_av_table_exists($pdo, 'oca_access_credential_assignees')) {
        try { $pdo->prepare('DELETE FROM oca_access_credential_assignees WHERE user_id = ?')->execute([$userId]); } catch (Throwable $e) {}
    }
    if (asr_av_table_exists($pdo, 'oca_access_credential_users')) {
        try { $pdo->prepare('DELETE FROM oca_access_credential_users WHERE user_id = ?')->execute([$userId]); } catch (Throwable $e) {}
    }
}

function asr_av_assigned_credentials_for_user(PDO $pdo, int $userId): array {
    if ($userId <= 0 || !asr_av_assigned_table_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT a.created_at AS assigned_at, c.id AS credential_id, c.login, c.comment AS credential_comment, c.status AS credential_status, r.id AS resource_id, r.title AS resource_title, r.url AS resource_url, r.category, g.title AS group_title
        FROM oca_access_credential_assignees a
        JOIN oca_access_credentials c ON c.id = a.credential_id
        JOIN oca_access_resources r ON r.id = c.resource_id
        LEFT JOIN oca_access_groups g ON g.id = r.group_id
        WHERE a.user_id = ?
        ORDER BY r.title ASC, c.login ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function asr_av_payment_tables_ready(PDO $pdo): bool {
    if (!asr_av_table_exists($pdo, 'oca_access_resource_payments')) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS oca_access_resource_payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_id INT UNSIGNED NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                payment_date DATE NULL,
                remind_days_before VARCHAR(100) NOT NULL DEFAULT "7",
                repeat_type VARCHAR(20) NOT NULL DEFAULT "none",
                auto_payment TINYINT(1) NOT NULL DEFAULT 0,
                auto_payment_period VARCHAR(20) NOT NULL DEFAULT "monthly",
                payment_amount DECIMAL(12,2) NULL,
                payment_currency VARCHAR(5) NOT NULL DEFAULT "₸",
                message TEXT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_resource_id (resource_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {}
    }
    if (asr_av_table_exists($pdo, 'oca_access_resource_payments')) {
        $paymentColumnExists = function (string $column) use ($pdo): bool {
            if (function_exists('asr_table_column_exists')) {
                try { return (bool)asr_table_column_exists($pdo, 'oca_access_resource_payments', $column); } catch (Throwable $e) {}
            }
            try {
                $stmt = $pdo->prepare('SHOW COLUMNS FROM `oca_access_resource_payments` LIKE ?');
                $stmt->execute([$column]);
                return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { return false; }
        };
        $paymentColumns = [
            'auto_payment' => 'ALTER TABLE `oca_access_resource_payments` ADD COLUMN `auto_payment` TINYINT(1) NOT NULL DEFAULT 0 AFTER `repeat_type`',
            'auto_payment_period' => 'ALTER TABLE `oca_access_resource_payments` ADD COLUMN `auto_payment_period` VARCHAR(20) NOT NULL DEFAULT "monthly" AFTER `auto_payment`',
            'payment_amount' => 'ALTER TABLE `oca_access_resource_payments` ADD COLUMN `payment_amount` DECIMAL(12,2) NULL AFTER `auto_payment_period`',
            'payment_currency' => 'ALTER TABLE `oca_access_resource_payments` ADD COLUMN `payment_currency` VARCHAR(5) NOT NULL DEFAULT "₸" AFTER `payment_amount`',
        ];
        foreach ($paymentColumns as $column => $sql) {
            if (!$paymentColumnExists($column)) {
                try { $pdo->exec($sql); } catch (Throwable $e) { /* не роняем страницу из-за старой схемы */ }
            }
        }
    }

    if (!asr_av_table_exists($pdo, 'oca_access_payment_recipients')) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS oca_access_payment_recipients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_payment_user (payment_id, user_id),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {}
    }
    if (!asr_av_table_exists($pdo, 'oca_access_payment_notifications')) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS oca_access_payment_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                remind_date DATE NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "sent",
                error_text TEXT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_payment_user_date (payment_id, user_id, remind_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (Throwable $e) {}
    }
    return asr_av_table_exists($pdo, 'oca_access_resource_payments')
        && asr_av_table_exists($pdo, 'oca_access_payment_recipients')
        && asr_av_table_exists($pdo, 'oca_access_payment_notifications');
}

function asr_av_payment_for_resource(PDO $pdo, int $resourceId): ?array {
    if ($resourceId <= 0 || !asr_av_payment_tables_ready($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM oca_access_resource_payments WHERE resource_id = ? LIMIT 1');
    $stmt->execute([$resourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_av_payments_for_resources(PDO $pdo, array $resourceIds): array {
    $resourceIds = array_values(array_filter(array_map('intval', $resourceIds)));
    if (!$resourceIds || !asr_av_payment_tables_ready($pdo)) return [];
    $in = implode(',', array_fill(0, count($resourceIds), '?'));
    $stmt = $pdo->prepare('SELECT * FROM oca_access_resource_payments WHERE resource_id IN (' . $in . ')');
    $stmt->execute($resourceIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['resource_id']] = $row;
    return $out;
}

function asr_av_payment_recipients(PDO $pdo, int $paymentId): array {
    if ($paymentId <= 0 || !asr_av_payment_tables_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT user_id FROM oca_access_payment_recipients WHERE payment_id = ? ORDER BY user_id ASC');
    $stmt->execute([$paymentId]);
    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function asr_av_payment_recipients_for_payments(PDO $pdo, array $paymentIds): array {
    $paymentIds = array_values(array_filter(array_map('intval', $paymentIds)));
    if (!$paymentIds || !asr_av_payment_tables_ready($pdo)) return [];
    $in = implode(',', array_fill(0, count($paymentIds), '?'));
    $stmt = $pdo->prepare('SELECT payment_id, user_id FROM oca_access_payment_recipients WHERE payment_id IN (' . $in . ') ORDER BY user_id ASC');
    $stmt->execute($paymentIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['payment_id']][] = (int)$row['user_id'];
    return $out;
}

function asr_av_users_for_payment(PDO $pdo): array {
    $where = [];
    if (asr_table_column_exists($pdo, 'oca_users', 'is_active')) $where[] = 'is_active = 1';
    // В списке получателей платежных уведомлений показываем только тех, у кого Telegram уже подключён.
    if (asr_table_column_exists($pdo, 'oca_users', 'telegram_chat_id')) $where[] = "COALESCE(telegram_chat_id, '') <> ''";
    else return [];
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $telegramUsername = asr_table_column_exists($pdo, 'oca_users', 'telegram_username') ? 'telegram_username' : 'NULL AS telegram_username';
    return $pdo->query("SELECT id, full_name, username, role, telegram_chat_id, {$telegramUsername} FROM oca_users {$whereSql} ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function asr_av_payment_recipient_names(PDO $pdo, array $userIds): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
    if (!$userIds) return [];
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare('SELECT id, full_name, username FROM oca_users WHERE id IN (' . $in . ') ORDER BY full_name ASC, username ASC');
    $stmt->execute($userIds);
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $names[(int)$u['id']] = trim((string)($u['full_name'] ?: $u['username']));
    }
    return $names;
}

function asr_av_save_payment(PDO $pdo, int $resourceId, array $data, array $recipientIds, int $userId): int {
    if ($resourceId <= 0) throw new RuntimeException('Ресурс не найден.');
    if (!asr_av_payment_tables_ready($pdo)) throw new RuntimeException('Таблицы напоминаний об оплате не найдены.');
    $paymentDate = trim((string)($data['payment_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) throw new RuntimeException('Укажите дату оплаты в формате ГГГГ-ММ-ДД.');
    $repeatType = (string)($data['repeat_type'] ?? 'none');
    if (!in_array($repeatType, ['none', 'monthly', 'yearly'], true)) $repeatType = 'none';
    $days = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($data['remind_days_before'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)), static fn($v) => $v >= 0 && $v <= 365)));
    sort($days);
    if (!$days) $days = [7];
    $message = trim((string)($data['message'] ?? ''));
    if (mb_strlen($message, 'UTF-8') > 2000) throw new RuntimeException('Сообщение слишком длинное.');
    $isEnabled = !empty($data['is_enabled']) ? 1 : 0;
    $autoPayment = !empty($data['auto_payment']) ? 1 : 0;
    $autoPaymentPeriod = (string)($data['auto_payment_period'] ?? 'monthly');
    if (!in_array($autoPaymentPeriod, ['monthly', 'yearly'], true)) $autoPaymentPeriod = 'monthly';
    $paymentAmountRaw = str_replace(',', '.', (string)($data['payment_amount'] ?? ''));
    $paymentAmountRaw = preg_replace('/\s+/u', '', $paymentAmountRaw) ?? '';
    $paymentAmount = null;
    if ($paymentAmountRaw !== '') {
        if (!is_numeric($paymentAmountRaw)) throw new RuntimeException('Укажите корректный размер оплаты.');
        $paymentAmount = round((float)$paymentAmountRaw, 2);
        if ($paymentAmount < 0 || $paymentAmount > 9999999999.99) throw new RuntimeException('Размер оплаты вне допустимого диапазона.');
    }
    $paymentCurrency = (string)($data['payment_currency'] ?? '₸');
    if (!in_array($paymentCurrency, ['₸', '₽', '$'], true)) $paymentCurrency = '₸';
    $stmt = $pdo->prepare('SELECT id FROM oca_access_resource_payments WHERE resource_id = ? LIMIT 1');
    $stmt->execute([$resourceId]);
    $paymentId = (int)$stmt->fetchColumn();
    if ($paymentId > 0) {
        $stmt = $pdo->prepare('UPDATE oca_access_resource_payments SET is_enabled=?, payment_date=?, remind_days_before=?, repeat_type=?, auto_payment=?, auto_payment_period=?, payment_amount=?, payment_currency=?, message=?, updated_by=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$isEnabled, $paymentDate, implode(',', $days), $repeatType, $autoPayment, $autoPaymentPeriod, $paymentAmount, $paymentCurrency, $message, $userId ?: null, $paymentId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO oca_access_resource_payments (resource_id, is_enabled, payment_date, remind_days_before, repeat_type, auto_payment, auto_payment_period, payment_amount, payment_currency, message, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$resourceId, $isEnabled, $paymentDate, implode(',', $days), $repeatType, $autoPayment, $autoPaymentPeriod, $paymentAmount, $paymentCurrency, $message, $userId ?: null, $userId ?: null]);
        $paymentId = (int)$pdo->lastInsertId();
    }

    // Сохраняем только подключённых к Telegram сотрудников — ровно тех, кого показываем в форме.
    $allowed = [];
    foreach (asr_av_users_for_payment($pdo) as $u) $allowed[(int)$u['id']] = true;
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn($id) => $id > 0 && isset($allowed[$id]))));
    $pdo->prepare('DELETE FROM oca_access_payment_recipients WHERE payment_id = ?')->execute([$paymentId]);
    if ($recipientIds) {
        $ins = $pdo->prepare('INSERT IGNORE INTO oca_access_payment_recipients (payment_id, user_id, created_at) VALUES (?, ?, NOW())');
        foreach ($recipientIds as $rid) $ins->execute([$paymentId, $rid]);
    }
    return $paymentId;
}

function asr_av_disable_payment(PDO $pdo, int $resourceId, int $userId): void {
    if ($resourceId <= 0 || !asr_av_payment_tables_ready($pdo)) return;
    $pdo->prepare('UPDATE oca_access_resource_payments SET is_enabled=0, updated_by=?, updated_at=NOW() WHERE resource_id=?')->execute([$userId ?: null, $resourceId]);
}

function asr_av_due_payment_notifications(PDO $pdo, DateTimeImmutable $now): array {
    if (!asr_av_payment_tables_ready($pdo)) return [];
    $today = $now->format('Y-m-d');
    $stmt = $pdo->query('SELECT p.*, r.title AS resource_title, r.url AS resource_url, r.comment AS resource_comment FROM oca_access_resource_payments p JOIN oca_access_resources r ON r.id=p.resource_id WHERE p.is_enabled=1 AND r.status="active"');
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $paymentDate = (string)($payment['payment_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) continue;
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$payment['remind_days_before'])), static fn($v) => $v >= 0)));
        if (!$days) $days = [7];
        $baseDate = new DateTimeImmutable($paymentDate . ' 00:00:00');
        $repeatType = (string)($payment['repeat_type'] ?? 'none');
        $candidates = [$baseDate];
        if ($repeatType === 'monthly' || $repeatType === 'yearly') {
            $current = $baseDate;
            for ($i = 0; $i < 240; $i++) {
                if ($current->format('Y-m-d') >= $today) { $candidates[] = $current; break; }
                $current = $repeatType === 'monthly' ? $current->modify('+1 month') : $current->modify('+1 year');
            }
        }
        foreach ($candidates as $payDateObj) {
            foreach ($days as $daysBefore) {
                $remindDate = $payDateObj->modify('-' . $daysBefore . ' days')->format('Y-m-d');
                if ($remindDate !== $today) continue;
                foreach (asr_av_payment_recipients($pdo, (int)$payment['id']) as $uid) {
                    $paymentForMessage = $payment;
                    $paymentForMessage['payment_date'] = $payDateObj->format('Y-m-d');
                    $rows[] = ['payment' => $paymentForMessage, 'user_id' => $uid, 'days_before' => $daysBefore, 'remind_date' => $today];
                }
            }
        }
    }
    return $rows;
}

function asr_av_notification_already_sent(PDO $pdo, int $paymentId, int $userId, string $remindDate): bool {
    if (!asr_av_payment_tables_ready($pdo)) return true;
    $stmt = $pdo->prepare('SELECT id FROM oca_access_payment_notifications WHERE payment_id=? AND user_id=? AND remind_date=? LIMIT 1');
    $stmt->execute([$paymentId, $userId, $remindDate]);
    return (bool)$stmt->fetchColumn();
}

function asr_av_record_payment_notification(PDO $pdo, int $paymentId, int $userId, string $remindDate, string $status, string $error = ''): void {
    if (!asr_av_payment_tables_ready($pdo)) return;
    $stmt = $pdo->prepare('INSERT INTO oca_access_payment_notifications (payment_id, user_id, remind_date, status, error_text, sent_at, created_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), error_text=VALUES(error_text), sent_at=NOW()');
    $stmt->execute([$paymentId, $userId, $remindDate, $status, mb_substr($error, 0, 1000, 'UTF-8')]);
}
