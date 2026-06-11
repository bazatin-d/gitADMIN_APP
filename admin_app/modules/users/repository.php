<?php
defined('ASR_ADMIN') || exit;

/**
 * SQL-слой модуля пользователей.
 * Здесь держим работу с таблицей oca_users, чтобы действия и страницы не тащили SQL в себя.
 */

function asr_users_repository_ensure_schema(PDO $pdo): void {
    if (function_exists('asr_ensure_users_role_schema')) {
        asr_ensure_users_role_schema($pdo);
    }
    if (function_exists('asr_ensure_users_password_plain_schema')) {
        asr_ensure_users_password_plain_schema($pdo);
    }
    try {
        if (function_exists('asr_table_column_exists_fresh') && !asr_table_column_exists_fresh($pdo, 'oca_users', 'remember_365_days')) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `remember_365_days` TINYINT(1) NOT NULL DEFAULT 0");
        } elseif (function_exists('asr_table_column_exists') && !asr_table_column_exists($pdo, 'oca_users', 'remember_365_days')) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `remember_365_days` TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        // Если ALTER TABLE недоступен, выполните миграцию вручную.
    }

    try {
        $telegramColumns = [
            'telegram_chat_id' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_chat_id` VARCHAR(64) NULL DEFAULT NULL",
            'telegram_username' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_username` VARCHAR(128) NULL DEFAULT NULL",
            'telegram_bind_token' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bind_token` VARCHAR(64) NULL DEFAULT NULL",
            'telegram_bound_at' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_bound_at` DATETIME NULL DEFAULT NULL",
            'telegram_broadcast_test_chat_id' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_chat_id` VARCHAR(64) NULL DEFAULT NULL",
            'telegram_broadcast_test_username' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_username` VARCHAR(128) NULL DEFAULT NULL",
            'telegram_broadcast_test_bound_at' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_bound_at` DATETIME NULL DEFAULT NULL",
            'telegram_broadcast_test_receive_broadcasts' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_receive_broadcasts` TINYINT(1) NOT NULL DEFAULT 1",
            'telegram_broadcast_test_notify_dialogs' => "ALTER TABLE `oca_users` ADD COLUMN `telegram_broadcast_test_notify_dialogs` TINYINT(1) NOT NULL DEFAULT 0",
            'pwa_dialog_notify_enabled' => "ALTER TABLE `oca_users` ADD COLUMN `pwa_dialog_notify_enabled` TINYINT(1) NOT NULL DEFAULT 0",
        ];
        foreach ($telegramColumns as $column => $sql) {
            $exists = function_exists('asr_table_column_exists_fresh')
                ? asr_table_column_exists_fresh($pdo, 'oca_users', $column)
                : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', $column));
            if (!$exists) {
                try { $pdo->exec($sql); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        // Если ALTER TABLE недоступен, выполните миграцию вручную.
    }

    try {
        $hasDialogsColumn = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', 'connect_to_dialogs')
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'connect_to_dialogs'));
        if (!$hasDialogsColumn) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `connect_to_dialogs` TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        // Если ALTER TABLE недоступен, выполните миграцию вручную.
    }

    try {
        $hasArchivedAt = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', 'archived_at')
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_at'));
        if (!$hasArchivedAt) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL");
        }
        $hasArchivedBy = function_exists('asr_table_column_exists_fresh')
            ? asr_table_column_exists_fresh($pdo, 'oca_users', 'archived_by')
            : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_by'));
        if (!$hasArchivedBy) {
            $pdo->exec("ALTER TABLE `oca_users` ADD COLUMN `archived_by` INT UNSIGNED NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // Если ALTER TABLE недоступен, выполните миграцию вручную.
    }
}



function asr_users_repository_column_exists(PDO $pdo, string $column): bool {
    return function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', $column)
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', $column));
}

function asr_users_repository_has_archived_column(PDO $pdo): bool {
    return function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'archived_at')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_at'));
}

function asr_users_repository_has_active_column(PDO $pdo): bool {
    return function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'is_active');
}

function asr_users_repository_has_remember_column(PDO $pdo): bool {
    return function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'remember_365_days')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'remember_365_days'));
}

function asr_users_repository_has_plain_password_column(PDO $pdo): bool {
    return function_exists('asr_table_column_exists_fresh')
        ? asr_table_column_exists_fresh($pdo, 'oca_users', 'password_plain')
        : (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'password_plain'));
}

function asr_users_repository_all(PDO $pdo): array {
    asr_users_repository_ensure_schema($pdo);
    $orderBy = "CASE WHEN id = 1 OR role = 'superadmin' THEN 0 ELSE 1 END ASC, full_name ASC, username ASC";
    $where = asr_users_repository_has_archived_column($pdo) ? "WHERE archived_at IS NULL" : "";
    return $pdo->query("SELECT * FROM oca_users {$where} ORDER BY {$orderBy}")->fetchAll(PDO::FETCH_ASSOC);
}

function asr_users_repository_archived_all(PDO $pdo): array {
    asr_users_repository_ensure_schema($pdo);
    if (!asr_users_repository_has_archived_column($pdo)) return [];
    return $pdo->query("SELECT * FROM oca_users WHERE archived_at IS NOT NULL ORDER BY archived_at DESC, full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function asr_users_repository_find(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM oca_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_users_repository_username_exists(PDO $pdo, string $username, int $exceptUserId = 0): bool {
    $username = trim($username);
    if ($username === '') return false;
    if ($exceptUserId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM oca_users WHERE username = ? AND id <> ? LIMIT 1');
        $stmt->execute([$username, $exceptUserId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM oca_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
    }
    return (bool)$stmt->fetchColumn();
}

function asr_users_repository_create(PDO $pdo, string $fullName, string $username, string $plainPassword, string $role, int $connectToDialogs = 0): int {
    asr_users_repository_ensure_schema($pdo);
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $hasActiveColumn = asr_users_repository_has_active_column($pdo);
    $hasPlainColumn = asr_users_repository_has_plain_password_column($pdo);
    $hasRememberColumn = asr_users_repository_has_remember_column($pdo);

    $columns = ['username', 'password_hash', 'role', 'full_name'];
    $values = [$username, $hash, $role, $fullName];
    if ($hasActiveColumn) { $columns[] = 'is_active'; $values[] = 1; }
    if ($hasPlainColumn) { $columns[] = 'password_plain'; $values[] = $plainPassword; }
    if ($hasRememberColumn) { $columns[] = 'remember_365_days'; $values[] = 0; }
    if (asr_users_repository_column_exists($pdo, 'connect_to_dialogs')) { $columns[] = 'connect_to_dialogs'; $values[] = $connectToDialogs === 1 ? 1 : 0; }

    $sql = 'INSERT INTO oca_users (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return (int)$pdo->lastInsertId();
}

function asr_users_repository_update(PDO $pdo, int $userId, string $fullName, string $username, string $role, string $plainPassword = '', int $remember365Days = 0, string $telegramChatId = '', string $telegramUsername = '', int $connectToDialogs = 0, int $broadcastTestReceiveBroadcasts = 1, int $broadcastTestNotifyDialogs = 0, int $pwaDialogNotifyEnabled = 0): void {
    asr_users_repository_ensure_schema($pdo);
    $hasPlainColumn = asr_users_repository_has_plain_password_column($pdo);
    $hasRememberColumn = asr_users_repository_has_remember_column($pdo);
    $remember365Days = $remember365Days === 1 ? 1 : 0;

    if ($plainPassword !== '') {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $sets = ['username = ?', 'password_hash = ?'];
        $values = [$username, $hash];
        if ($hasPlainColumn) {
            $sets[] = 'password_plain = ?';
            $values[] = $plainPassword;
        }
        $sets[] = 'role = ?';
        $sets[] = 'full_name = ?';
        $values[] = $role;
        $values[] = $fullName;
        if ($hasRememberColumn) {
            $sets[] = 'remember_365_days = ?';
            $values[] = $remember365Days;
        }
        if (asr_users_repository_column_exists($pdo, 'connect_to_dialogs')) { $sets[] = 'connect_to_dialogs = ?'; $values[] = $connectToDialogs === 1 ? 1 : 0; }
        if (asr_users_repository_column_exists($pdo, 'telegram_chat_id')) { $sets[] = 'telegram_chat_id = ?'; $values[] = $telegramChatId !== '' ? $telegramChatId : null; }
        if (asr_users_repository_column_exists($pdo, 'telegram_username')) { $sets[] = 'telegram_username = ?'; $values[] = $telegramUsername !== '' ? ltrim($telegramUsername, '@') : null; }
        if (asr_users_repository_column_exists($pdo, 'telegram_broadcast_test_receive_broadcasts')) { $sets[] = 'telegram_broadcast_test_receive_broadcasts = ?'; $values[] = $broadcastTestReceiveBroadcasts === 1 ? 1 : 0; }
        if (asr_users_repository_column_exists($pdo, 'telegram_broadcast_test_notify_dialogs')) { $sets[] = 'telegram_broadcast_test_notify_dialogs = ?'; $values[] = $broadcastTestNotifyDialogs === 1 ? 1 : 0; }
        if (asr_users_repository_column_exists($pdo, 'pwa_dialog_notify_enabled')) { $sets[] = 'pwa_dialog_notify_enabled = ?'; $values[] = $pwaDialogNotifyEnabled === 1 ? 1 : 0; }
        $values[] = $userId;
        $stmt = $pdo->prepare('UPDATE oca_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
        return;
    }

    $sets = ['username = ?', 'role = ?', 'full_name = ?'];
    $values = [$username, $role, $fullName];
    if ($hasRememberColumn) {
        $sets[] = 'remember_365_days = ?';
        $values[] = $remember365Days;
    }
    if (asr_users_repository_column_exists($pdo, 'connect_to_dialogs')) { $sets[] = 'connect_to_dialogs = ?'; $values[] = $connectToDialogs === 1 ? 1 : 0; }
    if (asr_users_repository_column_exists($pdo, 'telegram_chat_id')) { $sets[] = 'telegram_chat_id = ?'; $values[] = $telegramChatId !== '' ? $telegramChatId : null; }
    if (asr_users_repository_column_exists($pdo, 'telegram_username')) { $sets[] = 'telegram_username = ?'; $values[] = $telegramUsername !== '' ? ltrim($telegramUsername, '@') : null; }
    if (asr_users_repository_column_exists($pdo, 'telegram_broadcast_test_receive_broadcasts')) { $sets[] = 'telegram_broadcast_test_receive_broadcasts = ?'; $values[] = $broadcastTestReceiveBroadcasts === 1 ? 1 : 0; }
    if (asr_users_repository_column_exists($pdo, 'telegram_broadcast_test_notify_dialogs')) { $sets[] = 'telegram_broadcast_test_notify_dialogs = ?'; $values[] = $broadcastTestNotifyDialogs === 1 ? 1 : 0; }
    if (asr_users_repository_column_exists($pdo, 'pwa_dialog_notify_enabled')) { $sets[] = 'pwa_dialog_notify_enabled = ?'; $values[] = $pwaDialogNotifyEnabled === 1 ? 1 : 0; }
    $values[] = $userId;
    $stmt = $pdo->prepare('UPDATE oca_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}


function asr_users_repository_set_pwa_dialog_notify_enabled(PDO $pdo, int $userId, int $enabled): void {
    if ($userId <= 0) return;
    asr_users_repository_ensure_schema($pdo);
    if (!asr_users_repository_column_exists($pdo, 'pwa_dialog_notify_enabled')) return;
    $stmt = $pdo->prepare('UPDATE oca_users SET pwa_dialog_notify_enabled = ? WHERE id = ?');
    $stmt->execute([$enabled === 1 ? 1 : 0, $userId]);
}

function asr_users_repository_get_pwa_dialog_notify_enabled(PDO $pdo, int $userId): int {
    if ($userId <= 0) return 0;
    asr_users_repository_ensure_schema($pdo);
    if (!asr_users_repository_column_exists($pdo, 'pwa_dialog_notify_enabled')) return 0;
    $stmt = $pdo->prepare('SELECT pwa_dialog_notify_enabled FROM oca_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() === 1 ? 1 : 0;
}

function asr_users_repository_set_active(PDO $pdo, int $userId, int $state): void {
    if (!asr_users_repository_has_active_column($pdo)) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE oca_users SET is_active = ? WHERE id = ?');
    $stmt->execute([$state === 1 ? 1 : 0, $userId]);
}

function asr_users_repository_archive(PDO $pdo, int $userId, int $archivedBy = 0): void {
    asr_users_repository_ensure_schema($pdo);
    if (asr_users_repository_has_archived_column($pdo)) {
        $sets = ['archived_at = NOW()'];
        $values = [];
        if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_by')) {
            $sets[] = 'archived_by = ?';
            $values[] = $archivedBy ?: null;
        }
        if (asr_users_repository_has_active_column($pdo)) {
            $sets[] = 'is_active = 0';
        }
        $values[] = $userId;
        $stmt = $pdo->prepare('UPDATE oca_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
        return;
    }
    asr_users_repository_set_active($pdo, $userId, 0);
}

function asr_users_repository_restore(PDO $pdo, int $userId): void {
    asr_users_repository_ensure_schema($pdo);
    $sets = [];
    if (asr_users_repository_has_archived_column($pdo)) {
        $sets[] = 'archived_at = NULL';
        if (function_exists('asr_table_column_exists') && asr_table_column_exists($pdo, 'oca_users', 'archived_by')) $sets[] = 'archived_by = NULL';
    }
    if (asr_users_repository_has_active_column($pdo)) $sets[] = 'is_active = 1';
    if (!$sets) return;
    $stmt = $pdo->prepare('UPDATE oca_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute([$userId]);
}

function asr_users_repository_delete(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('DELETE FROM oca_users WHERE id = ?');
    $stmt->execute([$userId]);
}
