<?php
/**
 * Антиспам, нормализация контактов и безопасное сохранение заблокированных стартов OCA.
 * Старые функции сохранены, чтобы не ломать уже подключённые файлы.
 */

function asr_antispam_substr(string $value, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function asr_antispam_clean($value, int $max = 255): string {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    return asr_antispam_substr($value, $max);
}

function asr_normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    return $digits;
}

function asr_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return asr_antispam_substr($ip, 45);
        }
    }
    return '';
}

function asr_antispam_fail(string $message = 'Не удалось начать тест. Проверьте корректность введённых данных и попробуйте ещё раз.', int $code = 422): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function asr_antispam_contains_link_or_html(string $value): bool {
    if ($value === '') return false;
    if (preg_match('~https?://|www\.|<\s*/?\s*[a-z][^>]*>|\[[a-z]+\]~iu', $value)) return true;
    return $value !== strip_tags($value);
}

function asr_antispam_letter_count(string $value): int {
    if (preg_match_all('/[\p{L}]/u', $value, $matches)) return count($matches[0]);
    return 0;
}

function asr_antispam_has_long_repetition(string $value, int $limit = 8): bool {
    return (bool)preg_match('/(.)\1{' . max(1, $limit - 1) . ',}/u', $value);
}

function asr_antispam_looks_like_keyboard_mash(string $value): bool {
    $plain = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $plain = preg_replace('/[\s\-_.]+/u', '', $plain) ?? $plain;
    if ($plain === '') return true;

    $badExact = [
        'test','тест','qwerty','йцукен','asdf','asdasd','asdfasdf','qweqwe','12345','123456',
        'aaaa','аааа','name','noname','anonim','аноним','user','пользователь','xxx','spam','fake'
    ];
    if (in_array($plain, $badExact, true)) return true;
    if (preg_match('/^(?:qwe|asd|zxc|йцу|фыв|ячс){2,}$/iu', $plain)) return true;
    return false;
}

function asr_antispam_email_domain_is_blocked(string $email): bool {
    $email = function_exists('mb_strtolower') ? mb_strtolower(trim($email), 'UTF-8') : strtolower(trim($email));
    $parts = explode('@', $email);
    $domain = trim((string)end($parts));
    if ($domain === '') return true;

    $blockedExact = [
        'mailinator.com','10minutemail.com','guerrillamail.com','guerrillamail.net','guerrillamail.org',
        'guerrillamail.biz','guerrillamail.de','sharklasers.com','grr.la','guerrillamailblock.com',
        'pokemail.net','spam4.me','trashmail.com','yopmail.com','tempmail.com','temp-mail.org',
        'tempmail.net','tempmail.dev','throwawaymail.com','dispostable.com','fakeinbox.com',
        'getnada.com','maildrop.cc','mintemail.com'
    ];
    if (in_array($domain, $blockedExact, true)) return true;
    return (bool)preg_match('/(^|[.-])(tempmail|temp-mail|10minutemail|mailinator|guerrillamail)([.-]|$)/iu', $domain);
}

function asr_antispam_normalized_user(array $user): array {
    $name   = asr_antispam_clean($user['name'] ?? '', 255);
    $email  = asr_antispam_clean($user['email'] ?? '', 255);
    $phone  = asr_antispam_clean($user['phone'] ?? '', 80);
    $city   = asr_antispam_clean($user['city'] ?? '', 255);
    $gender = asr_antispam_clean($user['gender'] ?? 'Не указано', 80);
    $age    = asr_antispam_clean($user['age'] ?? 'Не указано', 80);
    $role   = asr_antispam_clean($user['role'] ?? 'Не указано', 255);
    $utm    = asr_antispam_clean($user['utm'] ?? '', 1000);

    return [
        'name'=>$name, 'email'=>$email, 'phone'=>$phone, 'phone_normalized'=>asr_normalize_phone($phone),
        'city'=>$city, 'gender'=>$gender, 'age'=>$age, 'role'=>$role, 'utm'=>$utm
    ];
}

function asr_check_start_user(array $user): array {
    $genericMessage = 'Не удалось начать тест. Проверьте корректность имени, телефона, email и города.';
    $normalized = asr_antispam_normalized_user($user);

    $honeypot = asr_antispam_clean($user['website'] ?? $user['company_site'] ?? '', 255);
    if ($honeypot !== '') return ['ok'=>false, 'reason'=>'honeypot', 'message'=>$genericMessage, 'user'=>$normalized];

    $startedAt = (int)($user['form_started_at'] ?? 0);
    if ($startedAt > 0) {
        $elapsedMs = (int)round(microtime(true) * 1000) - $startedAt;
        if ($elapsedMs >= 0 && $elapsedMs < 3000) {
            return ['ok'=>false, 'reason'=>'fast_submit', 'message'=>$genericMessage, 'user'=>$normalized];
        }
    }

    foreach ([$normalized['name'], $normalized['email'], $normalized['phone'], $normalized['city'], $normalized['gender'], $normalized['age'], $normalized['role'], $normalized['utm']] as $field) {
        if (asr_antispam_contains_link_or_html($field)) return ['ok'=>false, 'reason'=>'link_or_html', 'message'=>$genericMessage, 'user'=>$normalized];
        if (asr_antispam_has_long_repetition($field, 20)) return ['ok'=>false, 'reason'=>'long_repetition', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if ($normalized['name'] === '' || asr_antispam_letter_count($normalized['name']) < 2 || asr_antispam_looks_like_keyboard_mash($normalized['name'])) {
        return ['ok'=>false, 'reason'=>'bad_name', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    $digits = $normalized['phone_normalized'];
    if (strlen($digits) < 10 || preg_match('/^(\d)\1{9,}$/', $digits)) {
        return ['ok'=>false, 'reason'=>'bad_phone', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if (!filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok'=>false, 'reason'=>'bad_email', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    $emailLocal = explode('@', (function_exists('mb_strtolower') ? mb_strtolower($normalized['email'], 'UTF-8') : strtolower($normalized['email'])))[0] ?? '';
    if (in_array($emailLocal, ['test','qwerty','asdf','asdasd','fake','spam'], true)) {
        return ['ok'=>false, 'reason'=>'bad_email_local', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if (asr_antispam_email_domain_is_blocked($normalized['email'])) {
        return ['ok'=>false, 'reason'=>'bad_email_domain', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if ($normalized['city'] === '' || asr_antispam_letter_count($normalized['city']) < 2 || asr_antispam_looks_like_keyboard_mash($normalized['city'])) {
        return ['ok'=>false, 'reason'=>'bad_city', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if (preg_match('/[^\p{L}\s\-.]/u', $normalized['city'])) {
        return ['ok'=>false, 'reason'=>'bad_city_chars', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    if ($normalized['role'] === '' || asr_antispam_looks_like_keyboard_mash($normalized['role'])) {
        return ['ok'=>false, 'reason'=>'bad_role', 'message'=>$genericMessage, 'user'=>$normalized];
    }

    return ['ok'=>true, 'reason'=>'ok', 'message'=>'', 'user'=>$normalized];
}

function asr_spam_reason_label(string $reason): string {
    $map = [
        'honeypot' => 'скрытое поле заполнено ботом',
        'fast_submit' => 'слишком быстрая отправка формы',
        'link_or_html' => 'ссылка или HTML в полях формы',
        'long_repetition' => 'слишком много повторяющихся символов',
        'bad_name' => 'некорректное ФИО',
        'bad_phone' => 'некорректный телефон',
        'bad_email' => 'некорректный email',
        'bad_email_local' => 'мусорный email',
        'bad_email_domain' => 'временный или мусорный email-домен',
        'bad_city' => 'некорректный город',
        'bad_city_chars' => 'спецсимволы в городе',
        'bad_role' => 'некорректная роль',
    ];
    return $map[$reason] ?? $reason;
}

function asr_validate_start_user(array $user): array {
    $check = asr_check_start_user($user);
    if (!$check['ok']) asr_antispam_fail($check['message'] ?? null ?: 'Не удалось начать тест. Проверьте корректность введённых данных и попробуйте ещё раз.');
    return $check['user'];
}

function asr_column_exists_safe(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`','``',$table) . '` LIKE ?');
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}

function asr_try_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (asr_column_exists_safe($pdo, $table, $column)) return;
    try { $pdo->exec('ALTER TABLE `' . str_replace('`','``',$table) . '` ADD COLUMN `' . str_replace('`','``',$column) . '` ' . $definition); } catch (Throwable $e) {}
}

function asr_ensure_result_security_columns(PDO $pdo): void {
    asr_try_add_column($pdo, 'oca_results', 'phone_normalized', 'VARCHAR(32) NULL');
    asr_try_add_column($pdo, 'oca_results', 'spam_status', "VARCHAR(32) NOT NULL DEFAULT 'ok'");
    asr_try_add_column($pdo, 'oca_results', 'spam_reason', 'VARCHAR(255) NULL');
    asr_try_add_column($pdo, 'oca_results', 'spam_ip', 'VARCHAR(45) NULL');
    asr_try_add_column($pdo, 'oca_results', 'crm_contact_id', 'INT NULL');
    asr_try_add_column($pdo, 'oca_results', 'crm_deal_id', 'INT NULL');
    asr_try_add_column($pdo, 'oca_results', 'crm_sync_status', 'VARCHAR(32) NULL');
    asr_try_add_column($pdo, 'oca_results', 'crm_sync_error', 'TEXT NULL');
    asr_try_add_column($pdo, 'oca_results', 'crm_last_sync_at', 'DATETIME NULL');
}

function asr_insert_blocked_start(PDO $pdo, array $normalizedUser, string $reason): void {
    asr_ensure_result_security_columns($pdo);
    $answers = array_fill(0, 200, null);
    $insert = [
        'name' => $normalizedUser['name'] ?? '',
        'email' => $normalizedUser['email'] ?? '',
        'phone' => $normalizedUser['phone'] ?? '',
        'city' => $normalizedUser['city'] ?? '',
        'gender' => $normalizedUser['gender'] ?? 'Не указано',
        'age' => $normalizedUser['age'] ?? 'Не указано',
        'role' => $normalizedUser['role'] ?? 'Не указано',
        'utm' => $normalizedUser['utm'] ?? '',
        'graph_scores' => '',
        'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    foreach ([
        'status' => 'blocked',
        'current_page' => 0,
        'phone_normalized' => $normalizedUser['phone_normalized'] ?? '',
        'spam_status' => 'blocked',
        'spam_reason' => $reason,
        'spam_ip' => asr_client_ip(),
    ] as $col => $val) {
        if (asr_column_exists_safe($pdo, 'oca_results', $col)) $insert[$col] = $val;
    }
    $cols = array_keys($insert);
    $sql = 'INSERT INTO oca_results (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    try { $stmt = $pdo->prepare($sql); $stmt->execute(array_values($insert)); } catch (Throwable $e) {}
}
