<?php
defined('ASR_ADMIN') || exit;

// Совместимость после серии патчей: часть страниц уже умеет работать
// с индивидуальной видимостью доступов, а repository.php на сервере может
// быть старой версии. Все функции объявлены безопасно, только если их ещё нет.

if (!function_exists('asr_av_current_user_id')) {
    function asr_av_current_user_id(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('asr_av_current_user_can_bypass_individual')) {
    function asr_av_current_user_can_bypass_individual(): bool {
        $role = function_exists('asr_current_role') ? (string)asr_current_role() : (string)($_SESSION['role'] ?? '');
        if (in_array($role, ['superadmin', 'admin'], true)) return true;
        if (function_exists('isAdmin') && isAdmin()) return true;
        return false;
    }
}

if (!function_exists('asr_av_individual_table_ready')) {
    function asr_av_individual_table_ready(PDO $pdo): bool {
        if (function_exists('asr_av_table_exists')) return asr_av_table_exists($pdo, 'oca_access_credential_users');
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'oca_access_credential_users'");
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('asr_av_credential_allowed_user_ids')) {
    function asr_av_credential_allowed_user_ids(PDO $pdo, int $credentialId): array {
        if ($credentialId <= 0 || !asr_av_individual_table_ready($pdo)) return [];
        $stmt = $pdo->prepare('SELECT user_id FROM oca_access_credential_users WHERE credential_id = ? ORDER BY user_id ASC');
        $stmt->execute([$credentialId]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }
}

if (!function_exists('asr_av_set_credential_allowed_users')) {
    function asr_av_set_credential_allowed_users(PDO $pdo, int $credentialId, array $userIds): void {
        if ($credentialId <= 0 || !asr_av_individual_table_ready($pdo)) return;
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        $pdo->prepare('DELETE FROM oca_access_credential_users WHERE credential_id = ?')->execute([$credentialId]);
        if (!$userIds) return;
        $stmt = $pdo->prepare('INSERT IGNORE INTO oca_access_credential_users (credential_id, user_id, created_at) VALUES (?, ?, NOW())');
        foreach ($userIds as $userId) {
            $stmt->execute([$credentialId, $userId]);
        }
    }
}

if (!function_exists('asr_av_credential_is_visible_for_current_user')) {
    function asr_av_credential_is_visible_for_current_user(PDO $pdo, int $credentialId): bool {
        if ($credentialId <= 0) return false;
        if (asr_av_current_user_can_bypass_individual()) return true;
        $allowed = asr_av_credential_allowed_user_ids($pdo, $credentialId);
        if (!$allowed) return true;
        return in_array(asr_av_current_user_id(), $allowed, true);
    }
}
