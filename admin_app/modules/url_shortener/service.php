<?php
require_once __DIR__ . '/repository.php';
defined('ASR_ADMIN') || define('ASR_ADMIN', true);

function asr_shortener_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'oca_short_urls'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}



function asr_shortener_column_exists(PDO $pdo, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oca_short_urls' AND COLUMN_NAME = ?");
        $stmt->execute([$column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function asr_shortener_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    if (!asr_shortener_table_exists($pdo)) {
        return;
    }

    try {
        if (!asr_shortener_column_exists($pdo, 'is_permanent')) {
            $pdo->exec("ALTER TABLE `oca_short_urls` ADD COLUMN `is_permanent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `slug`");
        }
    } catch (PDOException $e) {
        // Если колонка уже успела появиться между проверкой и ALTER — не валим страницу.
        if (stripos($e->getMessage(), 'Duplicate column') === false && (string)$e->getCode() !== '42S21') {
            throw $e;
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oca_short_urls' AND INDEX_NAME = 'idx_is_permanent'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `oca_short_urls` ADD INDEX `idx_is_permanent` (`is_permanent`)");
        }
    } catch (Throwable $e) {
        // Индекс — ускоритель, не причина ломать укорачиватель.
    }
}

function asr_shortener_allowed_domains(): array {
    return [defined('SHORTENER_DOMAIN') ? SHORTENER_DOMAIN : 'localhost'];
}

function asr_shortener_default_domain(): string {
    return defined('SHORTENER_DOMAIN') ? SHORTENER_DOMAIN : 'localhost';
}

function asr_build_url_from_parts(array $parts): string {
    $url = '';
    if (!empty($parts['scheme'])) $url .= $parts['scheme'] . '://';
    if (!empty($parts['user'])) {
        $url .= $parts['user'];
        if (!empty($parts['pass'])) $url .= ':' . $parts['pass'];
        $url .= '@';
    }
    if (!empty($parts['host'])) $url .= $parts['host'];
    if (!empty($parts['port'])) $url .= ':' . $parts['port'];
    $url .= $parts['path'] ?? '';
    if (isset($parts['query']) && $parts['query'] !== '') $url .= '?' . $parts['query'];
    if (isset($parts['fragment']) && $parts['fragment'] !== '') $url .= '#' . $parts['fragment'];
    return $url;
}

function asr_normalize_shortener_target(string $url): string {
    $url = trim($url);
    if ($url === '') {
        throw new InvalidArgumentException('Введите исходную ссылку.');
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http','https'], true)) {
        throw new InvalidArgumentException('Введите корректную ссылку с доменом.');
    }

    // Исправляем частую ошибку: /page/#anchor?utm=... -> /page/?utm=...#anchor
    if (empty($parts['query']) && isset($parts['fragment']) && strpos($parts['fragment'], '?') !== false) {
        [$fragment, $query] = explode('?', $parts['fragment'], 2);
        $parts['fragment'] = $fragment;
        $parts['query'] = $query;
    }

    return asr_build_url_from_parts($parts);
}

function asr_validate_short_slug(string $slug): string {
    $slug = trim($slug);
    if ($slug === '') {
        throw new InvalidArgumentException('Укажите хвостик короткой ссылки.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $slug)) {
        throw new InvalidArgumentException('Хвостик должен быть от 3 до 64 символов: латиница, цифры, дефис или подчёркивание.');
    }
    return $slug;
}

function asr_generate_short_slug(PDO $pdo, string $domain, int $length = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $alphabet[random_int(0, $max)];
        }
        $stmt = $pdo->prepare("SELECT id FROM oca_short_urls WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        if (!$stmt->fetchColumn()) return $slug;
    }
    throw new RuntimeException('Не удалось сгенерировать уникальный хвостик. Попробуйте ещё раз.');
}

function asr_short_url(string $domain, string $slug): string {
    // Поддомен коротких ссылок полностью отдан под редиректы, поэтому /u/ в короткой ссылке больше не используем.
    // Старые записи в БД с другими domain показываем уже в новом формате, поиск при переходе идёт по slug.
    $domain = asr_shortener_default_domain();
    return function_exists('asr_config_shortener_url') ? asr_config_shortener_url(rawurlencode($slug)) : ('https://' . $domain . '/' . rawurlencode($slug));
}

function asr_shortener_error_redirect(string $message): void {
    header('Location: admin.php?tab=url_shortener&short_error=' . urlencode($message));
    exit;
}

function asr_shortener_success_redirect(string $message = 'Готово'): void {
    header('Location: admin.php?tab=url_shortener&short_saved=' . urlencode($message));
    exit;
}

function asr_utm_presets_table_exists(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'oca_utm_presets'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}


function asr_utm_ensure_presets_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    if (!asr_utm_presets_table_exists($pdo)) {
        return;
    }

    try {
        if (function_exists('asr_table_column_exists') && !asr_table_column_exists($pdo, 'oca_utm_presets', 'sort_order')) {
            $pdo->exec("ALTER TABLE `oca_utm_presets` ADD COLUMN `sort_order` INT UNSIGNED NOT NULL DEFAULT 100");
        }
        if (function_exists('asr_table_column_exists') && !asr_table_column_exists($pdo, 'oca_utm_presets', 'is_active')) {
            $pdo->exec("ALTER TABLE `oca_utm_presets` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (function_exists('asr_table_column_exists') && !asr_table_column_exists($pdo, 'oca_utm_presets', 'updated_at')) {
            $pdo->exec("ALTER TABLE `oca_utm_presets` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // Если у пользователя БД нет ALTER-прав, сами POST-действия всё равно покажут понятную ошибку.
    }
}

function asr_utm_type_labels(): array {
    return [
        'source' => 'utm_source',
        'medium' => 'utm_medium',
        'campaign' => 'utm_campaign',
        'content' => 'utm_content',
        'term' => 'utm_term',
    ];
}

function asr_utm_required_types(): array {
    return ['source', 'medium', 'campaign'];
}

function asr_utm_default_presets(): array {
    return [
        'source' => [
            ['id' => null, 'value' => 'private', 'description' => 'для личных сообщений сотрудников'],
            ['id' => null, 'value' => 'instagram', 'description' => 'если ссылка будет опубликована в Instagram'],
            ['id' => null, 'value' => 'telegram', 'description' => 'если ссылка будет опубликована в Telegram-канале'],
            ['id' => null, 'value' => 'vkontakte', 'description' => 'если ссылка будет опубликована во ВКонтакте'],
            ['id' => null, 'value' => 'facebook', 'description' => 'если ссылка будет опубликована в Facebook'],
            ['id' => null, 'value' => 'youtube', 'description' => 'если ссылка будет опубликована на YouTube'],
            ['id' => null, 'value' => 'tiktok', 'description' => 'если ссылка будет опубликована в TikTok'],
            ['id' => null, 'value' => 'site', 'description' => 'если ссылка будет опубликована на сайте АВМ'],
            ['id' => null, 'value' => 'bitrix', 'description' => 'для ссылок из роботов Bitrix24'],
            ['id' => null, 'value' => 'selzy', 'description' => 'email-рассылки Selzy'],
            ['id' => null, 'value' => 'whatsapp', 'description' => 'если ссылка будет опубликована в WhatsApp'],
            ['id' => null, 'value' => 'teletype', 'description' => 'если ссылка будет опубликована в Teletype'],
            ['id' => null, 'value' => 'rutube', 'description' => 'Rutube'],
            ['id' => null, 'value' => 'dzen', 'description' => 'Дзен'],
        ],
        'medium' => [
            ['id' => null, 'value' => 'zolotareva', 'description' => 'фамилии сотрудников для личных сообщений'],
            ['id' => null, 'value' => 'post', 'description' => 'пост в соцсетях и видеохостингах'],
            ['id' => null, 'value' => 'message', 'description' => 'рассылки в соцсетях и мессенджерах'],
            ['id' => null, 'value' => 'shorts', 'description' => 'shorts, reels, TikTok, stories'],
            ['id' => null, 'value' => 'record', 'description' => 'запись вебинара'],
            ['id' => null, 'value' => 'about', 'description' => 'информация в профиле, био и т.п.'],
            ['id' => null, 'value' => 'webinar', 'description' => 'вебинар'],
            ['id' => null, 'value' => 'email', 'description' => 'электронные письма'],
            ['id' => null, 'value' => 'freeacademy', 'description' => 'бесплатная академия'],
            ['id' => null, 'value' => 'butrimov', 'description' => 'сотрудник / партнёр'],
        ],
        'campaign' => [
            ['id' => null, 'value' => 'asr', 'description' => 'тест АСР'],
            ['id' => null, 'value' => 'crm_biuld', 'description' => 'внедрение CRM'],
            ['id' => null, 'value' => 'crm_design', 'description' => 'услуга проектирования CRM'],
            ['id' => null, 'value' => 'perezapusk', 'description' => 'начальный курс «Перезапуск руководителя»'],
            ['id' => null, 'value' => 'crm_web', 'description' => 'вебинар Базатина про Bitrix24'],
            ['id' => null, 'value' => 'dinner', 'description' => 'бизнес-ужин, завтрак или обед'],
            ['id' => null, 'value' => 'sait', 'description' => 'сайт'],
            ['id' => null, 'value' => 'minikurs', 'description' => 'бесплатные мини-курсы'],
        ],
        'content' => [],
        'term' => [
            ['id' => null, 'value' => '__today__', 'description' => 'сегодняшняя дата'],
            ['id' => null, 'value' => '28_01_2025_10-55', 'description' => 'ссылка на вебинарную комнату'],
            ['id' => null, 'value' => '28_01_2025_late', 'description' => 'для тех, кто опоздал'],
            ['id' => null, 'value' => '28_01_2025_13-00', 'description' => 'вариант вебинарной ссылки'],
            ['id' => null, 'value' => '23_12_2025', 'description' => 'дата / вариант ссылки'],
        ],
    ];
}


function asr_utm_today_value(): string {
    return date('d_m_Y');
}

function asr_utm_is_today_marker(string $type, string $value, string $description = ''): bool {
    $value = trim($value);
    $descriptionLower = function_exists('mb_strtolower') ? mb_strtolower(trim($description), 'UTF-8') : strtolower(trim($description));
    if ($type !== 'term') return false;
    if (in_array($value, ['today', '__today__', '{today}', '{{today}}'], true)) return true;
    if (preg_match('/^\d{2}_\d{2}_\d{4}$/', $value) && strpos($descriptionLower, 'сегодня') !== false) return true;
    return false;
}

function asr_utm_normalize_storage_value(string $type, string $value, string $description = ''): string {
    $value = trim($value);
    if (asr_utm_is_today_marker($type, $value, $description)) return '__today__';
    return $value;
}


function asr_utm_storage_key(string $type, string $value, string $description = ''): string {
    $normalized = asr_utm_normalize_storage_value($type, $value, $description);
    return function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
}

function asr_utm_seed_default_presets(PDO $pdo): void {
    if (!asr_utm_presets_table_exists($pdo)) return;
    asr_utm_ensure_presets_schema($pdo);

    try {
        // Старые записи вида 27_05_2026 + «сегодняшняя дата» превращаем в служебный маркер.
        // Так пункт остается динамическим и получает нормальный id для редактирования/удаления.
        $pdo->exec("UPDATE oca_utm_presets
            SET value = '__today__', description = 'сегодняшняя дата', is_active = 1
            WHERE type = 'term'
              AND value REGEXP '^[0-9]{2}_[0-9]{2}_[0-9]{4}$'
              AND LOWER(description) LIKE '%сегодня%'
              AND NOT EXISTS (
                  SELECT 1 FROM (
                      SELECT id FROM oca_utm_presets WHERE type = 'term' AND value = '__today__' LIMIT 1
                  ) AS existing_today
              )");

        $stmt = $pdo->prepare("INSERT INTO oca_utm_presets (type, value, description, sort_order, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                description = IF(description IS NULL OR description = '', VALUES(description), description)");

        foreach (asr_utm_default_presets() as $type => $items) {
            $sort = 10;
            foreach ($items as $item) {
                $value = asr_utm_normalize_storage_value($type, (string)($item['value'] ?? ''), (string)($item['description'] ?? ''));
                if ($value === '') continue;
                $stmt->execute([
                    $type,
                    $value,
                    (string)($item['description'] ?? ''),
                    $sort,
                ]);
                $sort += 10;
            }
        }
    } catch (Throwable $e) {
        // Если автозаполнение справочника не удалось, страница всё равно должна открываться.
    }
}

function asr_utm_preset_display(array $item, string $type): array {
    $rawValue = (string)($item['value'] ?? '');
    $description = (string)($item['description'] ?? '');
    $isToday = asr_utm_is_today_marker($type, $rawValue, $description);
    $value = $isToday ? asr_utm_today_value() : $rawValue;
    $editValue = $isToday ? '__today__' : $rawValue;
    $description = $isToday && trim($description) === '' ? 'сегодняшняя дата' : $description;
    return [
        'value' => $value,
        'edit_value' => $editValue,
        'description' => $description,
        'is_today' => $isToday,
    ];
}

function asr_get_utm_presets(PDO $pdo): array {
    $fallback = asr_utm_default_presets();
    if (!asr_utm_presets_table_exists($pdo)) {
        return $fallback;
    }

    asr_utm_ensure_presets_schema($pdo);
    asr_utm_seed_default_presets($pdo);

    $result = [];
    foreach (array_keys(asr_utm_type_labels()) as $type) {
        $result[$type] = [];
    }

    try {
        $stmt = $pdo->query("SELECT id, type, value, description FROM oca_utm_presets WHERE is_active = 1 ORDER BY type, sort_order, value");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $type = (string)($row['type'] ?? '');
            if (!array_key_exists($type, $result)) continue;
            $value = (string)($row['value'] ?? '');
            if ($value === '') continue;
            $description = (string)($row['description'] ?? '');
            $result[$type][] = [
                'id' => (int)$row['id'],
                'value' => asr_utm_normalize_storage_value($type, $value, $description),
                'description' => $description,
            ];
        }
    } catch (Throwable $e) {
        return $fallback;
    }

    return $result;
}

function asr_validate_utm_type(string $type): string {
    $type = trim($type);
    if (!array_key_exists($type, asr_utm_type_labels())) {
        throw new InvalidArgumentException('Некорректный тип UTM-значения.');
    }
    return $type;
}

function asr_validate_utm_value(string $value, string $type = '', string $description = ''): string {
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('Введите значение UTM.');
    }
    $value = asr_utm_normalize_storage_value($type, $value, $description);
    if (mb_strlen($value, 'UTF-8') > 100) {
        throw new InvalidArgumentException('UTM-значение не должно быть длиннее 100 символов.');
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $value)) {
        throw new InvalidArgumentException('UTM-значение: только латиница, цифры, точка, дефис и подчёркивание. Без пробелов и кириллицы.');
    }
    return $value;
}

function asr_utm_redirect(string $message = '', string $error = ''): void {
    $url = 'admin.php?tab=url_shortener';
    if ($message !== '') $url .= '&utm_saved=' . urlencode($message);
    if ($error !== '') $url .= '&utm_error=' . urlencode($error);
    header('Location: ' . $url . '#utm-builder');
    exit;
}
