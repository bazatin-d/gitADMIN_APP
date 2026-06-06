<?php
defined('ASR_ADMIN') || exit;

require_once __DIR__ . '/service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
$action = (string)($_POST['action'] ?? '');
$known = [
    'av_create_group','av_update_group','av_delete_group',
    'av_create_resource','av_update_resource','av_archive_resource','av_restore_resource','av_delete_resource_permanent',
    'av_create_credential','av_update_credential','av_archive_credential','av_restore_credential','av_delete_credential_permanent',
    'av_generate_password','av_copy_credential','av_share_messenger','av_send_email','av_import_csv','av_export_csv','av_save_payment','av_disable_payment','av_run_payment_reminders','av_reorder_credentials','av_bulk_update_groups'
];
if (!in_array($action, $known, true)) return;

$category = asr_av_normalize_category((string)($_POST['category'] ?? $_GET['category'] ?? 'sites'));
$workCategory = in_array($category, ['audit', 'import_export'], true) ? asr_av_normalize_category((string)($_POST['work_category'] ?? 'sites')) : $category;
try {
    if (!asr_av_schema_ready($pdo)) {
        throw new RuntimeException('Таблицы модуля «Доступы» не найдены. Выполните SQL-миграцию.');
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);

    // Страховка от частичной загрузки файлов/старого OPcache: сортировка не должна падать
    // с «Call to undefined function asr_av_reorder_credentials()».
    if (!function_exists('asr_av_reorder_credentials')) {
        function asr_av_reorder_credentials(PDO $pdo, int $resourceId, array $credentialIds, int $userId = 0): void {
            $credentialIds = array_values(array_unique(array_filter(array_map('intval', $credentialIds), static fn($id) => $id > 0)));
            if ($resourceId <= 0 || !$credentialIds) return;
            $columnExists = false;
            try {
                $stmt = $pdo->prepare('SHOW COLUMNS FROM `oca_access_credentials` LIKE ?');
                $stmt->execute(['sort_order']);
                $columnExists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            if (!$columnExists) {
                try { $pdo->exec('ALTER TABLE `oca_access_credentials` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 100 AFTER `status`'); } catch (Throwable $e) {}
            }
            $stmt = $pdo->prepare('UPDATE oca_access_credentials SET sort_order = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND resource_id = ?');
            $order = 100;
            foreach ($credentialIds as $credentialId) {
                $stmt->execute([$order, $userId ?: null, (int)$credentialId, $resourceId]);
                $order += 100;
            }
        }
    }

    if ($action === 'av_export_csv') {
        asr_av_export_csv($pdo, $workCategory);
    }

    if ($action === 'av_create_group') {
        asr_av_require('create');
        $title = asr_av_normalize_text((string)($_POST['group_title'] ?? ''), 120, true, 'Название группы');
        $sortOrder = max(0, min(9999, (int)($_POST['sort_order'] ?? 100)));
        $color = (string)($_POST['group_color'] ?? $_POST['color'] ?? '#F4E4A6');
        $textColor = (string)($_POST['group_text_color'] ?? $_POST['text_color'] ?? '#4B5563');
        $iconKey = (string)($_POST['group_icon'] ?? $_POST['icon_key'] ?? 'flask');
        $id = asr_av_create_group($pdo, $category, $title, $userId, $sortOrder, $color, $textColor, $iconKey);
        asr_av_audit($pdo, 'group_created', null, null, ['group_id' => $id, 'title' => $title, 'category' => $category, 'color' => $color, 'text_color' => $textColor, 'icon' => $iconKey]);
        asr_av_redirect($category, 'Группа добавлена.');
    }

    if ($action === 'av_update_group') {
        asr_av_require('edit');
        $groupId = (int)($_POST['group_id'] ?? 0);
        $title = asr_av_normalize_text((string)($_POST['group_title'] ?? ''), 120, true, 'Название группы');
        $sortOrder = max(0, min(9999, (int)($_POST['sort_order'] ?? 100)));
        $color = (string)($_POST['group_color'] ?? $_POST['color'] ?? '#F4E4A6');
        $textColor = (string)($_POST['group_text_color'] ?? $_POST['text_color'] ?? '#4B5563');
        $iconKey = (string)($_POST['group_icon'] ?? $_POST['icon_key'] ?? 'flask');
        asr_av_update_group($pdo, $groupId, $title, $userId, $sortOrder, $color, $textColor, $iconKey);
        asr_av_audit($pdo, 'group_updated', null, null, ['group_id' => $groupId, 'title' => $title, 'color' => $color, 'text_color' => $textColor, 'icon' => $iconKey]);
        asr_av_redirect($category, 'Группа обновлена.');
    }

    if ($action === 'av_delete_group') {
        asr_av_require('edit');
        $groupId = (int)($_POST['group_id'] ?? 0);
        asr_av_delete_group($pdo, $groupId);
        asr_av_audit($pdo, 'group_deleted', null, null, ['group_id' => $groupId]);
        asr_av_redirect($category, 'Группа удалена.');
    }

    if ($action === 'av_create_resource' || $action === 'av_update_resource') {
        asr_av_require($action === 'av_create_resource' ? 'create' : 'edit');
        $data = [
            'category' => asr_av_normalize_category((string)($_POST['resource_category'] ?? $category)),
            'group_id' => max(0, (int)($_POST['group_id'] ?? 0)),
            'title' => asr_av_normalize_text((string)($_POST['title'] ?? ''), 180, true, 'Название'),
            'url' => asr_av_normalize_url((string)($_POST['url'] ?? '')),
            'comment' => asr_av_normalize_comment((string)($_POST['comment'] ?? '')),
        ];
        if ($action === 'av_create_resource') {
            $id = asr_av_create_resource($pdo, $data, $userId);
            asr_av_audit($pdo, 'resource_created', $id, null, ['title' => $data['title']]);
            asr_av_redirect($data['category'], 'Ресурс добавлен.');
        } else {
            $id = (int)($_POST['resource_id'] ?? 0);
            asr_av_update_resource($pdo, $id, $data, $userId);
            asr_av_audit($pdo, 'resource_updated', $id, null, ['title' => $data['title']]);
            asr_av_redirect($data['category'], 'Ресурс обновлён.');
        }
    }

    if ($action === 'av_archive_resource' || $action === 'av_restore_resource') {
        asr_av_require($action === 'av_archive_resource' ? 'archive' : 'restore');
        $id = (int)($_POST['resource_id'] ?? 0);
        $row = asr_av_find_resource($pdo, $id);
        if (!$row) throw new RuntimeException('Ресурс не найден.');
        $status = $action === 'av_archive_resource' ? 'archived' : 'active';
        asr_av_set_resource_status($pdo, $id, $status, $userId);
        asr_av_audit($pdo, $status === 'archived' ? 'resource_archived' : 'resource_restored', $id);
        asr_av_redirect($category, $status === 'archived' ? 'Ресурс отправлен в архив.' : 'Ресурс восстановлен.');
    }



    if ($action === 'av_delete_resource_permanent') {
        if (!function_exists('asr_current_role') || asr_current_role() !== 'superadmin') throw new RuntimeException('Удалять безвозвратно может только суперадминистратор.');
        $id = (int)($_POST['resource_id'] ?? 0);
        asr_av_delete_resource_permanent($pdo, $id);
        asr_av_audit($pdo, 'resource_deleted_permanent', $id);
        asr_av_redirect($category, 'Ресурс удалён безвозвратно.');
    }

    if ($action === 'av_delete_credential_permanent') {
        if (!function_exists('asr_current_role') || asr_current_role() !== 'superadmin') throw new RuntimeException('Удалять безвозвратно может только суперадминистратор.');
        $id = (int)($_POST['credential_id'] ?? 0);
        $cred = asr_av_find_credential($pdo, $id);
        asr_av_delete_credential_permanent($pdo, $id);
        asr_av_audit($pdo, 'credential_deleted_permanent', $cred ? (int)$cred['resource_id'] : null, $id);
        asr_av_redirect($category, 'Доступ удалён безвозвратно.');
    }

    if ($action === 'av_create_credential' || $action === 'av_update_credential') {
        asr_av_require($action === 'av_create_credential' ? 'create' : 'edit');
        $resourceId = (int)($_POST['resource_id'] ?? 0);
        $login = asr_av_normalize_text((string)($_POST['login'] ?? ''), 255, true, 'Логин');
        $password = (string)($_POST['password'] ?? '');
        $comment = asr_av_normalize_comment((string)($_POST['comment'] ?? ''));
        if ($action === 'av_create_credential') {
            if ($password === '') throw new RuntimeException('Укажите пароль.');
            $id = asr_av_create_credential($pdo, $resourceId, $login, asr_access_vault_encrypt($password), $comment, $userId);
            if (asr_av_can('individual_access')) { $allowedIds = $_POST['allowed_user_ids'] ?? []; if (!is_array($allowedIds)) $allowedIds = []; asr_av_save_credential_allowed_users($pdo, $id, $allowedIds); }
            $assignedIds = $_POST['assigned_user_ids'] ?? []; if (!is_array($assignedIds)) $assignedIds = []; asr_av_save_credential_assigned_users($pdo, $id, $assignedIds);
            asr_av_audit($pdo, 'credential_created', $resourceId, $id, ['login' => $login]);
            asr_av_redirect($category, 'Доступ добавлен.');
        } else {
            $id = (int)($_POST['credential_id'] ?? 0);
            $enc = $password !== '' ? asr_access_vault_encrypt($password) : null;
            asr_av_update_credential($pdo, $id, $login, $enc, $comment, $userId, $resourceId);
            if (asr_av_can('individual_access')) { $allowedIds = $_POST['allowed_user_ids'] ?? []; if (!is_array($allowedIds)) $allowedIds = []; asr_av_save_credential_allowed_users($pdo, $id, $allowedIds); }
            $assignedIds = $_POST['assigned_user_ids'] ?? []; if (!is_array($assignedIds)) $assignedIds = []; asr_av_save_credential_assigned_users($pdo, $id, $assignedIds);
            asr_av_audit($pdo, 'credential_updated', $resourceId, $id, ['login' => $login, 'password_changed' => $password !== '']);
            asr_av_redirect($category, 'Доступ обновлён.');
        }
    }

    if ($action === 'av_archive_credential' || $action === 'av_restore_credential') {
        asr_av_require($action === 'av_archive_credential' ? 'archive' : 'restore');
        $id = (int)($_POST['credential_id'] ?? 0);
        $cred = asr_av_find_credential($pdo, $id);
        if (!$cred) throw new RuntimeException('Доступ не найден.');
        $status = $action === 'av_archive_credential' ? 'archived' : 'active';
        asr_av_set_credential_status($pdo, $id, $status, $userId);
        asr_av_audit($pdo, $status === 'archived' ? 'credential_archived' : 'credential_restored', (int)$cred['resource_id'], $id);
        asr_av_redirect($category, $status === 'archived' ? 'Доступ отправлен в архив.' : 'Доступ восстановлен.');
    }

    if ($action === 'av_send_email') {
        asr_av_send_credential_email($pdo, (int)($_POST['credential_id'] ?? 0), (int)($_POST['to_user_id'] ?? 0), (string)($_POST['note'] ?? ''));
        asr_av_redirect($category, 'Доступ отправлен по SMTP.');
    }

    if ($action === 'av_copy_credential' || $action === 'av_share_messenger') {
        asr_av_require($action === 'av_copy_credential' ? 'copy' : 'share');
        $cred = asr_av_find_credential($pdo, (int)($_POST['credential_id'] ?? 0));
        if ($cred) asr_av_audit($pdo, $action === 'av_copy_credential' ? 'credential_copied' : 'credential_shared_messenger', (int)$cred['resource_id'], (int)$cred['id']);
        asr_av_redirect($category, $action === 'av_copy_credential' ? 'Копирование зафиксировано в журнале.' : 'Отправка в мессенджер зафиксирована в журнале.');
    }

    if ($action === 'av_save_payment') {
        asr_av_require('edit');
        $resourceId = (int)($_POST['resource_id'] ?? 0);
        $resource = asr_av_find_resource($pdo, $resourceId);
        if (!$resource) throw new RuntimeException('Ресурс не найден.');
        $recipientIds = $_POST['payment_recipients'] ?? [];
        if (!is_array($recipientIds)) $recipientIds = [];
        $paymentId = asr_av_save_payment($pdo, $resourceId, [
            'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
            'payment_date' => (string)($_POST['payment_date'] ?? ''),
            'remind_days_before' => (string)($_POST['remind_days_before'] ?? ''),
            'repeat_type' => (string)($_POST['repeat_type'] ?? 'none'),
            'auto_payment' => isset($_POST['auto_payment']) ? 1 : 0,
            'auto_payment_period' => (string)($_POST['auto_payment_period'] ?? 'monthly'),
            'payment_amount' => (string)($_POST['payment_amount'] ?? ''),
            'payment_currency' => (string)($_POST['payment_currency'] ?? '₸'),
            'message' => (string)($_POST['message'] ?? ''),
        ], $recipientIds, $userId);
        asr_av_audit($pdo, 'payment_settings_saved', $resourceId, null, ['payment_id' => $paymentId, 'recipients' => array_values(array_map('intval', $recipientIds))]);
        asr_av_redirect($category, 'Настройки оплаты сохранены.');
    }

    if ($action === 'av_disable_payment') {
        asr_av_require('edit');
        $resourceId = (int)($_POST['resource_id'] ?? 0);
        asr_av_disable_payment($pdo, $resourceId, $userId);
        asr_av_audit($pdo, 'payment_disabled', $resourceId);
        asr_av_redirect($category, 'Напоминание об оплате выключено.');
    }

    if ($action === 'av_run_payment_reminders') {
        asr_av_require('edit');
        $result = asr_av_run_payment_reminders($pdo);
        asr_av_redirect($category, 'Проверка напоминаний выполнена. Отправлено: ' . (int)$result['sent'] . ', ошибок: ' . (int)$result['failed'] . ', пропущено: ' . (int)$result['skipped'] . '.');
    }

    if ($action === 'av_reorder_credentials') {
        asr_av_require('edit');
        $resourceId = (int)($_POST['resource_id'] ?? 0);
        $orderRaw = (string)($_POST['credential_order'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $orderRaw, -1, PREG_SPLIT_NO_EMPTY)), static fn($id) => $id > 0)));
        asr_av_reorder_credentials($pdo, $resourceId, $ids, $userId);
        asr_av_audit($pdo, 'credential_order_updated', $resourceId, null, ['order' => $ids]);
        asr_av_redirect($category, 'Порядок доступов сохранён.');
    }

    if ($action === 'av_bulk_update_groups') {
        asr_av_require('edit');
        $rows = $_POST['groups'] ?? [];
        if (!is_array($rows)) $rows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $groupId = (int)($row['id'] ?? 0);
            if ($groupId <= 0) continue;
            $title = asr_av_normalize_text((string)($row['title'] ?? ''), 120, true, 'Название группы');
            $sortOrder = max(0, min(9999, (int)($row['sort_order'] ?? 100)));
            $color = (string)($row['color'] ?? '#F4E4A6');
            $textColor = (string)($row['text_color'] ?? '#4B5563');
            $iconKey = (string)($row['icon_key'] ?? 'flask');
            asr_av_update_group($pdo, $groupId, $title, $userId, $sortOrder, $color, $textColor, $iconKey);
        }
        asr_av_audit($pdo, 'groups_bulk_updated', null, null, ['count' => count($rows), 'category' => $category]);
        asr_av_redirect($category, 'Группы сохранены.');
    }

    if ($action === 'av_import_csv') {
        asr_av_require('import_export');
        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) throw new RuntimeException('Выберите CSV-файл.');
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) throw new RuntimeException('Не удалось прочитать CSV.');
        $header = fgetcsv($fh, 0, ';');
        if (!$header) throw new RuntimeException('CSV пустой.');
        $header = array_map(fn($v) => trim((string)$v, " \t\n\r\0\x0B\xEF\xBB\xBF"), $header);
        $map = array_flip($header);
        $count = 0;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $get = function($key) use ($row, $map) { return isset($map[$key], $row[$map[$key]]) ? (string)$row[$map[$key]] : ''; };
            $cat = asr_av_normalize_category($get('category') ?: $workCategory);
            if ($cat === 'archive') $cat = 'sites';
            $groupId = asr_av_get_or_create_group($pdo, $cat, $get('group') ?: 'Импорт', $userId);
            $resData = [
                'category' => $cat,
                'group_id' => $groupId,
                'title' => asr_av_normalize_text($get('title'), 180, true, 'Название'),
                'url' => asr_av_normalize_url($get('url')),
                'comment' => asr_av_normalize_comment($get('resource_comment')),
            ];
            $resourceId = asr_av_create_resource($pdo, $resData, $userId);
            if ($get('login') !== '' || $get('password') !== '') {
                asr_av_create_credential($pdo, $resourceId, asr_av_normalize_text($get('login'), 255, false, 'Логин'), asr_access_vault_encrypt($get('password')), asr_av_normalize_comment($get('credential_comment')), $userId);
            }
            $count++;
        }
        fclose($fh);
        asr_av_audit($pdo, 'resources_imported', null, null, ['category' => $workCategory, 'count' => $count]);
        asr_av_redirect($category, 'Импорт завершён. Добавлено ресурсов: ' . $count . '.');
    }

    asr_av_redirect($category);
} catch (Throwable $e) {
    asr_av_redirect($category, '', $e->getMessage());
}
