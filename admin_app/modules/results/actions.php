<?php
defined('ASR_ADMIN') || exit;

/**
 * Модуль «Результаты АСР».
 * Здесь живут действия списка и карточки результата: письма, CRM, спам, редактирование и удаление.
 */

if (!function_exists('asr_results_redirect')) {
    function asr_results_redirect(string $url = 'admin.php'): void {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('asr_results_base_list_url')) {
    function asr_results_base_list_url(array $params = []): string {
        $query = [];
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }
        return 'admin.php' . ($query ? ('?' . http_build_query($query)) : '');
    }
}

if (!function_exists('asr_update_result_crm_fields')) {
    function asr_update_result_crm_fields(PDO $pdo, int $resultId, array $b24Result): void {
        $sets = [];
        $values = [];

        $sets[] = 'crm_deal = ?';
        $values[] = $b24Result['deal_url'] ?? '';
        $sets[] = 'crm_contact = ?';
        $values[] = $b24Result['contact_url'] ?? '';

        $optional = [
            'crm_contact_id' => $b24Result['contact_id'] ?? null,
            'crm_deal_id' => $b24Result['deal_id'] ?? null,
            'crm_sync_status' => 'success',
            'crm_sync_error' => '',
            'crm_last_sync_at' => date('Y-m-d H:i:s'),
        ];
        foreach ($optional as $column => $value) {
            if (asr_table_column_exists($pdo, 'oca_results', $column)) {
                $sets[] = '`' . $column . '` = ?';
                $values[] = $value;
            }
        }

        $values[] = $resultId;
        $stmt = $pdo->prepare('UPDATE oca_results SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }
}

if (!function_exists('asr_mark_result_crm_error')) {
    function asr_mark_result_crm_error(PDO $pdo, int $resultId, string $message): void {
        $sets = [];
        $values = [];
        if (asr_table_column_exists($pdo, 'oca_results', 'crm_sync_status')) {
            $sets[] = 'crm_sync_status = ?';
            $values[] = 'error';
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'crm_sync_error')) {
            $sets[] = 'crm_sync_error = ?';
            $values[] = mb_substr($message, 0, 500, 'UTF-8');
        }
        if (asr_table_column_exists($pdo, 'oca_results', 'crm_last_sync_at')) {
            $sets[] = 'crm_last_sync_at = ?';
            $values[] = date('Y-m-d H:i:s');
        }
        if (!$sets) {
            return;
        }
        $values[] = $resultId;
        $stmt = $pdo->prepare('UPDATE oca_results SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }
}

if (!function_exists('asr_refresh_result_crm')) {
    function asr_refresh_result_crm(PDO $pdo, int $resultId): bool {
        $stmt = $pdo->prepare('SELECT * FROM oca_results WHERE id = ? LIMIT 1');
        $stmt->execute([$resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Результат не найден.');
        }

        $integrationPath = dirname(__DIR__, 3) . '/b24_integration/sync_test.php';
        if (!file_exists($integrationPath)) {
            throw new RuntimeException('Файл интеграции Bitrix24 не найден.');
        }
        require_once $integrationPath;

        $baseUrl = asr_current_base_url();
        $isCompleted = (($row['status'] ?? 'completed') === 'completed');
        $payload = [
            'test_id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'age' => (string)($row['age'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'utm' => (string)($row['utm'] ?? ''),
            'deal_url' => (string)(($row['crm_deal'] ?? '') ?: ($row['crm_deal_id'] ?? '')),
            'manager_link' => $baseUrl . 'admin.php?view=' . (int)$row['id'],
            'client_link' => $baseUrl . 'admin.php?shared=' . encodeId((int)$row['id']),
            'resume_link' => asr_result_resume_url($row),
        ];

        if ($isCompleted) {
            if (!function_exists('sendTestToBitrix24')) {
                throw new RuntimeException('Функция полной синхронизации Bitrix24 не найдена.');
            }
            $result = sendTestToBitrix24($payload);
        } else {
            if (!function_exists('sendPartialTestToBitrix24')) {
                throw new RuntimeException('Функция промежуточной синхронизации Bitrix24 не найдена.');
            }
            $result = sendPartialTestToBitrix24($payload);
        }

        if (!$result || empty($result['deal_url'])) {
            asr_mark_result_crm_error($pdo, $resultId, 'Bitrix24 не вернул сделку при ручном обновлении.');
            return false;
        }

        asr_update_result_crm_fields($pdo, $resultId, $result);
        return true;
    }
}

// Отправка письма клиенту из списка результатов.
if (asr_can_work_results() && isset($_GET['send_client_email'])) {
    $resultId = (int)$_GET['send_client_email'];
    $requestedType = (string)($_GET['email_type'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM oca_results WHERE id = ? LIMIT 1');
    $stmt->execute([$resultId]);
    $resultRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultRow) {
        asr_results_redirect('admin.php?mail_error=' . urlencode('Результат не найден'));
    }

    $email = trim((string)($resultRow['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        asr_results_redirect('admin.php?mail_error=' . urlencode('У клиента не указан корректный email'));
    }

    $isCompletedEmail = (($resultRow['status'] ?? 'completed') === 'completed');
    $type = $requestedType === 'resume' ? 'resume' : ($requestedType === 'client_graph' ? 'client_graph' : ($isCompletedEmail ? 'client_graph' : 'resume'));

    if ($type === 'resume' && trim((string)($resultRow['resume_token'] ?? '')) === '') {
        asr_results_redirect('admin.php?mail_error=' . urlencode('У незавершённого теста нет ссылки продолжения'));
    }

    $settings = asr_get_all_settings();
    $vars = asr_result_template_vars($resultRow);

    if ($type === 'resume') {
        $subject = asr_render_template($settings['resume_email_subject'] ?? '', $vars);
        $message = asr_render_template($settings['resume_email_body'] ?? '', $vars);
    } else {
        $subject = asr_render_template($settings['client_graph_email_subject'] ?? '', $vars);
        $message = asr_render_template($settings['client_graph_email_body'] ?? '', $vars);
    }

    $sent = asr_send_smtp_mail($email, $subject, $message, asr_smtp_config_from_settings($settings));
    asr_results_redirect('admin.php?' . ($sent ? 'mail_sent=1' : 'mail_error=' . urlencode('Письмо не отправлено. Проверьте SMTP-настройки')));
}

// Ручное восстановление записи из раздела «Спам».
if (isAdmin() && isset($_GET['restore_spam'])) {
    $resultId = (int)$_GET['restore_spam'];
    if ($resultId > 0 && asr_table_column_exists($pdo, 'oca_results', 'spam_status')) {
        $fields = ['spam_status = ?'];
        $values = ['ok'];
        if (asr_table_column_exists($pdo, 'oca_results', 'spam_reason')) {
            $fields[] = 'spam_reason = ?';
            $values[] = '';
        }
        $values[] = $resultId;
        $stmt = $pdo->prepare('UPDATE oca_results SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }
    asr_results_redirect('admin.php?tab=results&spam=blocked&restored=1');
}

// Ручное обновление / повторное создание контакта и сделки CRM.
if (asr_can_work_results() && isset($_GET['refresh_crm'])) {
    $resultId = (int)$_GET['refresh_crm'];
    try {
        $ok = asr_refresh_result_crm($pdo, $resultId);
        asr_results_redirect('admin.php' . ($ok ? '?crm_refreshed=1' : '?crm_error=' . urlencode('Не удалось обновить CRM. Детали смотрите в логах.')));
    } catch (Throwable $e) {
        asr_mark_result_crm_error($pdo, $resultId, $e->getMessage());
        asr_results_redirect('admin.php?crm_error=' . urlencode('Не удалось обновить CRM. Детали смотрите в логах.'));
    }
}

// Редактирование результата.
if (asr_can_work_results() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_result') {
    $stmt = $pdo->prepare('UPDATE oca_results SET name=?, phone=?, email=?, city=?, role=?, crm_deal=?, crm_contact=? WHERE id=?');
    $stmt->execute([
        trim((string)($_POST['name'] ?? '')),
        trim((string)($_POST['phone'] ?? '')),
        trim((string)($_POST['email'] ?? '')),
        trim((string)($_POST['city'] ?? '')),
        trim((string)($_POST['role'] ?? '')),
        trim((string)($_POST['crm_deal'] ?? '')),
        trim((string)($_POST['crm_contact'] ?? '')),
        (int)($_POST['id'] ?? 0),
    ]);
    asr_results_redirect('admin.php?page=' . (int)($_POST['current_page'] ?? 1) . '&search=' . urlencode((string)($_POST['search_query'] ?? '')));
}

// Удаление результата.
if (isAdmin() && isset($_GET['delete_result'])) {
    $stmt = $pdo->prepare('DELETE FROM oca_results WHERE id = ?');
    $stmt->execute([(int)$_GET['delete_result']]);
    asr_results_redirect('admin.php');
}
