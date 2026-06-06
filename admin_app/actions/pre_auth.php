<?php
defined('ASR_ADMIN') || exit;


// Динамический manifest для установленного приложения админки.
if (isset($_GET['admin_manifest'])) {
    $manifestSettings = asr_get_all_settings();
    $appName = trim((string)($manifestSettings['app_name'] ?? 'АСР АВМ')) ?: 'АСР АВМ';
    header('Content-Type: application/manifest+json; charset=UTF-8');
    echo json_encode([
        'name' => $appName,
        'short_name' => mb_substr($appName, 0, 24, 'UTF-8'),
        'start_url' => '/admin.php',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#f8fafc',
        'theme_color' => '#FFA048',
        'icons' => [
            ['src' => '/pwa/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/pwa/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- ЛОГИКА ЭКСПОРТА В CSV (Только для Администратора) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isAdmin()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=oca_results_'.date('d_m_Y').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['ID', 'Дата', 'Имя Фамилия', 'Телефон', 'Email', 'Город', 'Роль', 'Баллы профиля', 'UTM', 'Сделка CRM', 'Контакт CRM'], ';');
    $stmt = $pdo->query("SELECT * FROM oca_results ORDER BY created_at DESC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, [$row['id'], $row['created_at'], $row['name'], $row['phone'], $row['email'], $row['city'], $row['role'], $row['graph_scores'], $row['utm'], $row['crm_deal'], $row['crm_contact']], ';');
    }
    fclose($output);
    exit;
}

asr_ensure_users_role_schema($pdo);
asr_ensure_users_password_plain_schema($pdo);

// --- ЛОГИКА АВТОРИЗАЦИИ И СБРОСА ПАРОЛЯ ---
$error = '';
$msg_success = '';

// Обработка запроса на сброс пароля
if (isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $reset_email = trim($_POST['reset_email']);
    $stmt = $pdo->prepare("SELECT * FROM oca_users WHERE username = ?");
    $stmt->execute([$reset_email]);
    $user = $stmt->fetch();

    if ($user && asr_table_column_exists($pdo, 'oca_users', 'is_active') && (int)($user['is_active'] ?? 1) !== 1) {
        $error = "Пользователь временно заблокирован. Обратитесь к администратору.";
    } elseif ($user) {
        // Генерируем новый пароль из 8 символов
        $new_password = substr(str_shuffle("23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ"), 0, 8);
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        if (asr_table_column_exists($pdo, 'oca_users', 'password_plain')) {
            $pdo->prepare("UPDATE oca_users SET password_hash = ?, password_plain = ? WHERE id = ?")->execute([$new_hash, $new_password, $user['id']]);
        } else {
            $pdo->prepare("UPDATE oca_users SET password_hash = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
        }

        // Отправка письма
        $subject = "Восстановление доступа - Система OCA";
        $message = "Здравствуйте, " . $user['full_name'] . "!\n\n";
        $message .= "Ваш пароль для входа в систему был сброшен.\n\n";
        $message .= "Ваш логин: " . $user['username'] . "\n";
        $message .= "Ваш новый пароль: " . $new_password . "\n\n";
        $message .= "Вы можете изменить этот пароль после входа в систему (раздел Пользователи -> Изменить).\n";
        
        $mail_domain = function_exists('asr_config_mail_domain') ? asr_config_mail_domain() : 'localhost';
        $headers = "From: no-reply@" . $mail_domain . "\r\n" .
                   "Reply-To: no-reply@" . $mail_domain . "\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";

        mail($reset_email, $subject, $message, $headers);
        
        $msg_success = "Новый пароль отправлен на " . htmlspecialchars($reset_email);
    } else {
        $error = "Пользователь с таким Email не найден";
    }
}

if (!empty($_SESSION['deactivated_notice'])) {
    $error = $_SESSION['deactivated_notice'];
    unset($_SESSION['deactivated_notice']);
}

// Обработка входа
if (isset($_POST['login_action'])) {
    $user_input = trim($_POST['username']);
    $pass_input = trim($_POST['password']);
    $stmt = $pdo->prepare("SELECT * FROM oca_users WHERE username = ?");
    $stmt->execute([$user_input]);
    $found_user = $stmt->fetch();
    if ($found_user) {
        if (asr_table_column_exists($pdo, 'oca_users', 'is_active') && (int)($found_user['is_active'] ?? 1) !== 1) {
            $error = 'Пользователь временно заблокирован. Обратитесь к администратору.';
        } else {
            $auth_ok = false;
            if (password_verify($pass_input, $found_user['password_hash'])) { $auth_ok = true; }
            if ($auth_ok) {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = (int)$found_user['id'];
                $_SESSION['user_role'] = asr_normalize_admin_role((string)$found_user['role']); 
                $_SESSION['full_name'] = $found_user['full_name'];
                $rememberEnabled = asr_table_column_exists($pdo, 'oca_users', 'remember_365_days') && (int)($found_user['remember_365_days'] ?? 0) === 1;
                $_SESSION['remember_365_days'] = $rememberEnabled ? 1 : 0;
                if ($rememberEnabled) {
                    if (function_exists('asr_persist_current_session_for_days')) {
                        asr_persist_current_session_for_days(365);
                    }
                    if (function_exists('asr_issue_remember_token')) {
                        asr_issue_remember_token($pdo, (int)$found_user['id'], 365);
                    }
                } elseif (function_exists('asr_forget_remember_cookie')) {
                    asr_forget_remember_cookie();
                }
                header('Location: admin.php'); exit;
            } else { $error = 'Неверный пароль'; }
        }
    } else {
        $error = 'Пользователь не найден';
    }
}

if (isset($_GET['logout'])) {
    $logoutUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if (function_exists('asr_clear_remember_tokens')) {
        asr_clear_remember_tokens($pdo, $logoutUserId);
    }
    $_SESSION = [];
    if (function_exists('asr_expire_current_session_cookie')) {
        asr_expire_current_session_cookie();
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}
