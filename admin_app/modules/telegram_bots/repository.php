<?php
defined('ASR_ADMIN') || exit;

function asr_tg_table_exists(PDO $pdo, string $table): bool {
    $safeTable = str_replace('`', '``', $table);

    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // На некоторых хостингах SHOW TABLES может быть ограничен правами,
        // хотя SELECT по таблице работает. Ниже есть безопасная проверка через SELECT.
    }

    try {
        $stmt = $pdo->query("SELECT 1 FROM `{$safeTable}` LIMIT 0");
        return $stmt !== false;
    } catch (Throwable $e) {
        return false;
    }
}


function asr_tg_column_exists(PDO $pdo, string $table, string $column): bool {
    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    } catch (Throwable $e) {
        // См. комментарий в asr_tg_table_exists: SHOW может быть недоступен,
        // поэтому дублируем проверку через SELECT LIMIT 0.
    }

    try {
        $stmt = $pdo->query("SELECT `{$safeColumn}` FROM `{$safeTable}` LIMIT 0");
        return $stmt !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (asr_tg_column_exists($pdo, $table, $column)) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD COLUMN `" . str_replace('`', '``', $column) . "` " . $definition);
    } catch (PDOException $e) {
        // MySQL 1060: Duplicate column name.
        // На некоторых хостингах SHOW COLUMNS может вернуть некорректный результат
        // при одновременном/повторном открытии модуля, а колонка уже создана предыдущим запросом.
        // Для авто-миграций это не ошибка: колонка уже есть, идём дальше.
        if ((string)$e->getCode() === '42S21' || strpos($e->getMessage(), '1060') !== false || stripos($e->getMessage(), 'Duplicate column') !== false) {
            return;
        }
        throw $e;
    }
}

function asr_tg_index_exists(PDO $pdo, string $table, string $index): bool {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = ?");
        $stmt->execute([$index]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function asr_tg_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void {
    if (asr_tg_index_exists($pdo, $table, $index)) return;
    try {
        $pdo->exec("ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD INDEX `" . str_replace('`', '``', $index) . "` " . $definition);
    } catch (Throwable $e) {
        // Индексы ускоряют Ajax-обновления, но отсутствие прав на ALTER не должно ломать модуль.
    }
}
function asr_tg_schema_ready(PDO $pdo): bool {
    return asr_tg_table_exists($pdo, 'oca_telegram_bots')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_subscribers')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_logs')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcasts')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_tags')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_tags')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')
        && asr_tg_table_exists($pdo, 'oca_telegram_bot_dialogs');
}


function asr_tg_repository_ensure_custom_fields_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_custom_fields` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `title` VARCHAR(190) NOT NULL,
        `code` VARCHAR(80) NOT NULL,
        `field_type` VARCHAR(30) NOT NULL DEFAULT 'text',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tg_custom_field_code` (`bot_id`, `code`),
        KEY `idx_tg_custom_fields_active` (`bot_id`, `is_active`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_subscriber_custom_values` (
        `subscriber_id` INT UNSIGNED NOT NULL,
        `field_id` INT UNSIGNED NOT NULL,
        `value_text` TEXT NULL,
        `value_number` DECIMAL(18,4) NULL DEFAULT NULL,
        `value_date` DATE NULL DEFAULT NULL,
        `value_datetime` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`subscriber_id`, `field_id`),
        KEY `idx_tg_custom_value_field` (`field_id`),
        KEY `idx_tg_custom_value_number` (`field_id`, `value_number`),
        KEY `idx_tg_custom_value_date` (`field_id`, `value_date`),
        KEY `idx_tg_custom_value_datetime` (`field_id`, `value_datetime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function asr_tg_repository_ensure_schema(PDO $pdo): void {
    if (asr_tg_table_exists($pdo, 'oca_users')) {
        try {
            asr_tg_add_column_if_missing($pdo, 'oca_users', 'connect_to_dialogs', "TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bots` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `title` VARCHAR(190) NOT NULL,
        `bot_username` VARCHAR(190) NULL DEFAULT NULL,
        `bot_first_name` VARCHAR(190) NULL DEFAULT NULL,
        `telegram_bot_id` BIGINT NULL DEFAULT NULL,
        `bot_token_encrypted` TEXT NOT NULL,
        `webhook_secret` VARCHAR(128) NOT NULL,
        `webhook_secret_token` VARCHAR(128) NULL DEFAULT NULL,
        `webhook_url` VARCHAR(500) NULL DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
        `welcome_text` TEXT NULL,
        `last_error` TEXT NULL,
        `last_webhook_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created_by` (`created_by`),
        KEY `idx_status` (`status`),
        KEY `idx_bot_username` (`bot_username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'channel_type', "VARCHAR(30) NOT NULL DEFAULT 'telegram' AFTER `created_by`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'vk_group_id', "VARCHAR(80) NULL DEFAULT NULL AFTER `telegram_bot_id`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'vk_screen_name', "VARCHAR(190) NULL DEFAULT NULL AFTER `vk_group_id`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'vk_confirmation_code', "VARCHAR(190) NULL DEFAULT NULL AFTER `vk_screen_name`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'vk_secret_key', "VARCHAR(190) NULL DEFAULT NULL AFTER `vk_confirmation_code`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bots', 'vk_api_token_encrypted', "TEXT NULL AFTER `vk_secret_key`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_subscribers` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `telegram_user_id` BIGINT NOT NULL,
        `chat_id` BIGINT NOT NULL,
        `username` VARCHAR(190) NULL DEFAULT NULL,
        `first_name` VARCHAR(190) NULL DEFAULT NULL,
        `last_name` VARCHAR(190) NULL DEFAULT NULL,
        `language_code` VARCHAR(20) NULL DEFAULT NULL,
        `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
        `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_bot_user` (`bot_id`, `telegram_user_id`),
        KEY `idx_bot_status` (`bot_id`, `status`),
        KEY `idx_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'phone', "VARCHAR(80) NULL DEFAULT NULL AFTER `is_bot`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'email', "VARCHAR(190) NULL DEFAULT NULL AFTER `phone`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'admin_note', "TEXT NULL AFTER `email`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'ref', "VARCHAR(255) NULL DEFAULT NULL AFTER `admin_note`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'utm_source', "VARCHAR(190) NULL DEFAULT NULL AFTER `ref`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'utm_medium', "VARCHAR(190) NULL DEFAULT NULL AFTER `utm_source`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'utm_campaign', "VARCHAR(190) NULL DEFAULT NULL AFTER `utm_medium`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'utm_content', "VARCHAR(190) NULL DEFAULT NULL AFTER `utm_campaign`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_subscribers', 'utm_term', "VARCHAR(190) NULL DEFAULT NULL AFTER `utm_content`");


    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_custom_fields` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `title` VARCHAR(190) NOT NULL,
        `code` VARCHAR(80) NOT NULL,
        `field_type` VARCHAR(30) NOT NULL DEFAULT 'text',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tg_custom_field_code` (`bot_id`, `code`),
        KEY `idx_tg_custom_fields_active` (`bot_id`, `is_active`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_subscriber_custom_values` (
        `subscriber_id` INT UNSIGNED NOT NULL,
        `field_id` INT UNSIGNED NOT NULL,
        `value_text` TEXT NULL,
        `value_number` DECIMAL(18,4) NULL DEFAULT NULL,
        `value_date` DATE NULL DEFAULT NULL,
        `value_datetime` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`subscriber_id`, `field_id`),
        KEY `idx_tg_custom_value_field` (`field_id`),
        KEY `idx_tg_custom_value_number` (`field_id`, `value_number`),
        KEY `idx_tg_custom_value_date` (`field_id`, `value_date`),
        KEY `idx_tg_custom_value_datetime` (`field_id`, `value_datetime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_tags` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `name` VARCHAR(80) NOT NULL,
        `color` VARCHAR(20) NOT NULL DEFAULT '#F3F4F6',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_bot_tag_name` (`bot_id`, `name`),
        KEY `idx_bot` (`bot_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_subscriber_tags` (
        `subscriber_id` INT UNSIGNED NOT NULL,
        `tag_id` INT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`subscriber_id`, `tag_id`),
        KEY `idx_tag` (`tag_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_commands` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `command` VARCHAR(32) NOT NULL,
        `description` VARCHAR(256) NOT NULL,
        `scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `step_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_bot_command` (`bot_id`, `command`),
        KEY `idx_bot_order` (`bot_id`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Runtime v0.1.2: таблица команд могла быть создана раньше, когда в ней ещё не было
    // привязки к сценарию и шагу. Не делаем runtime-ALTER на тяжёлых страницах,
    // но лёгкая проверка справочника команд нужна перед сохранением/исполнением команд.
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_commands', 'scenario_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `description`');
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_commands', 'step_id', 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `scenario_id`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_messages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NULL DEFAULT NULL,
        `direction` VARCHAR(10) NOT NULL,
        `message_type` VARCHAR(40) NOT NULL DEFAULT 'text',
        `message_text` MEDIUMTEXT NULL,
        `telegram_message_id` BIGINT NULL DEFAULT NULL,
        `payload_json` MEDIUMTEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bot_created` (`bot_id`, `created_at`),
        KEY `idx_subscriber` (`subscriber_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_dialogs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NOT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'new',
        `assigned_user_id` INT UNSIGNED NULL DEFAULT NULL,
        `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_message_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `last_message_at` DATETIME NULL DEFAULT NULL,
        `last_direction` VARCHAR(10) NULL DEFAULT NULL,
        `read_at` DATETIME NULL DEFAULT NULL,
        `closed_at` DATETIME NULL DEFAULT NULL,
        `spam_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_bot_subscriber` (`bot_id`, `subscriber_id`),
        KEY `idx_bot_status` (`bot_id`, `status`),
        KEY `idx_assigned` (`assigned_user_id`),
        KEY `idx_last_message` (`last_message_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $indexes = $pdo->query("SHOW INDEX FROM `oca_telegram_bot_dialogs`")->fetchAll(PDO::FETCH_ASSOC);
        $hasOldSubscriberUnique = false;
        $hasBotSubscriberUnique = false;
        foreach ($indexes as $indexRow) {
            $keyName = (string)($indexRow['Key_name'] ?? '');
            if ($keyName === 'uniq_subscriber') $hasOldSubscriberUnique = true;
            if ($keyName === 'uniq_bot_subscriber') $hasBotSubscriberUnique = true;
        }
        if ($hasOldSubscriberUnique) {
            try { $pdo->exec("ALTER TABLE `oca_telegram_bot_dialogs` DROP INDEX `uniq_subscriber`"); } catch (Throwable $e) {}
        }
        if (!$hasBotSubscriberUnique) {
            try { $pdo->exec("ALTER TABLE `oca_telegram_bot_dialogs` ADD UNIQUE KEY `uniq_bot_subscriber` (`bot_id`, `subscriber_id`)"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // Если прав на ALTER нет, таблица продолжит работать со старым индексом, но новые выборки всё равно разделяют диалоги по каналам.
    }

    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_messages', 'idx_dialog_poll', '(`bot_id`, `subscriber_id`, `id`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_dialogs', 'idx_dialog_view_poll', '(`status`, `assigned_user_id`, `last_message_at`)');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_dialogs', 'idx_dialog_updated', '(`updated_at`)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NULL DEFAULT NULL,
        `level` VARCHAR(20) NOT NULL DEFAULT 'info',
        `event_type` VARCHAR(80) NOT NULL,
        `message` TEXT NULL,
        `context_json` MEDIUMTEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bot_created` (`bot_id`, `created_at`),
        KEY `idx_event` (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_dialog_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `auto_close_incoming` TINYINT(1) NOT NULL DEFAULT 0,
        `auto_reply_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `auto_reply_text` TEXT NULL,
        `auto_reply_attachment_json` MEDIUMTEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_bot_dialog_settings` (`bot_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_dialog_settings', 'auto_reply_attachment_json', 'MEDIUMTEXT NULL');
    } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_broadcasts` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `title` VARCHAR(190) NOT NULL,
        `message_text` MEDIUMTEXT NOT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
        `scheduled_at` DATETIME NULL DEFAULT NULL,
        `total_recipients` INT UNSIGNED NOT NULL DEFAULT 0,
        `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_error` TEXT NULL,
        `started_at` DATETIME NULL DEFAULT NULL,
        `finished_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bot_created` (`bot_id`, `created_at`),
        KEY `idx_status` (`status`),
        KEY `idx_status_scheduled_at` (`status`, `scheduled_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'parse_mode', "VARCHAR(20) NOT NULL DEFAULT 'HTML' AFTER `message_text`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'media_type', "VARCHAR(30) NULL DEFAULT NULL AFTER `parse_mode`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'media_url', "VARCHAR(700) NULL DEFAULT NULL AFTER `media_type`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'media_file_path', "VARCHAR(700) NULL DEFAULT NULL AFTER `media_url`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'media_file_name', "VARCHAR(255) NULL DEFAULT NULL AFTER `media_file_path`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'payload_json', "MEDIUMTEXT NULL AFTER `media_file_name`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'segment_json', "MEDIUMTEXT NULL AFTER `payload_json`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'disable_web_page_preview', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `media_file_name`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'queued_at', "DATETIME NULL DEFAULT NULL AFTER `started_at`");
    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcasts', 'cancelled_at', "DATETIME NULL DEFAULT NULL AFTER `finished_at`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_broadcast_recipients` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `broadcast_id` BIGINT UNSIGNED NOT NULL,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NOT NULL,
        `chat_id` BIGINT NOT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `last_error` TEXT NULL,
        `telegram_message_id` BIGINT NULL DEFAULT NULL,
        `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `next_attempt_at` DATETIME NULL DEFAULT NULL,
        `processing_at` DATETIME NULL DEFAULT NULL,
        `sent_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_broadcast_subscriber` (`broadcast_id`, `subscriber_id`),
        KEY `idx_status_scheduled` (`status`, `scheduled_at`),
        KEY `idx_status_next_attempt` (`status`, `next_attempt_at`),
        KEY `idx_broadcast_status` (`broadcast_id`, `status`),
        KEY `idx_bot_status` (`bot_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    asr_tg_add_column_if_missing($pdo, 'oca_telegram_bot_broadcast_recipients', 'next_attempt_at', 'DATETIME NULL DEFAULT NULL AFTER `scheduled_at`');
    asr_tg_add_index_if_missing($pdo, 'oca_telegram_bot_broadcast_recipients', 'idx_status_next_attempt', '(`status`, `next_attempt_at`)');
}

function asr_tg_log(PDO $pdo, ?int $botId, string $level, string $eventType, string $message = '', array $context = []): void {
    try {
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_logs')) return;
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_logs (bot_id, level, event_type, message, context_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$botId ?: null, mb_substr($level, 0, 20), mb_substr($eventType, 0, 80), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    } catch (Throwable $e) {}
}

function asr_tg_bots_all(PDO $pdo): array {
    asr_tg_repository_ensure_schema($pdo);
    $sql = "SELECT b.*,
        (SELECT COUNT(*) FROM oca_telegram_bot_subscribers s WHERE s.bot_id = b.id) AS subscribers_count,
        (SELECT COUNT(*) FROM oca_telegram_bot_messages m WHERE m.bot_id = b.id) AS messages_count
        FROM oca_telegram_bots b ORDER BY b.id DESC";
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_bot_find(PDO $pdo, int $botId): ?array {
    if ($botId <= 0) return null;
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bots WHERE id = ? LIMIT 1');
    $stmt->execute([$botId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_tg_bot_create(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bots (created_by, channel_type, title, bot_username, bot_first_name, telegram_bot_id, vk_group_id, vk_screen_name, vk_confirmation_code, vk_secret_key, vk_api_token_encrypted, bot_token_encrypted, webhook_secret, webhook_secret_token, webhook_url, status, welcome_text, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $data['created_by'] ?? null,
        $data['channel_type'] ?? 'telegram',
        $data['title'],
        $data['bot_username'] ?? null,
        $data['bot_first_name'] ?? null,
        $data['telegram_bot_id'] ?? null,
        $data['vk_group_id'] ?? null,
        $data['vk_screen_name'] ?? null,
        $data['vk_confirmation_code'] ?? null,
        $data['vk_secret_key'] ?? null,
        $data['vk_api_token_encrypted'] ?? null,
        $data['bot_token_encrypted'] ?? '',
        $data['webhook_secret'],
        $data['webhook_secret_token'] ?? null,
        $data['webhook_url'] ?? null,
        $data['status'] ?? 'draft',
        $data['welcome_text'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function asr_tg_bot_update(PDO $pdo, int $botId, array $data): void {
    $allowed = ['channel_type','title','bot_username','bot_first_name','telegram_bot_id','vk_group_id','vk_screen_name','vk_confirmation_code','vk_secret_key','vk_api_token_encrypted','bot_token_encrypted','webhook_secret','webhook_secret_token','webhook_url','status','welcome_text','last_error','last_webhook_at'];
    $sets = [];
    $values = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $sets[] = "`{$key}` = ?";
            $values[] = $data[$key];
        }
    }
    if (!$sets) return;
    $sets[] = '`updated_at` = NOW()';
    $values[] = $botId;
    $stmt = $pdo->prepare('UPDATE oca_telegram_bots SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}

function asr_tg_bot_delete(PDO $pdo, int $botId): void {
    if ($botId <= 0) return;
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_tags')) {
        $pdo->prepare('DELETE st FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_subscribers s ON s.id = st.subscriber_id WHERE s.bot_id = ?')->execute([$botId]);
    }
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) {
        $pdo->prepare('DELETE FROM oca_telegram_bot_commands WHERE bot_id = ?')->execute([$botId]);
    }
    $pdo->prepare('DELETE FROM oca_telegram_bot_messages WHERE bot_id = ?')->execute([$botId]);
    $pdo->prepare('DELETE FROM oca_telegram_bot_subscribers WHERE bot_id = ?')->execute([$botId]);
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_tags')) {
        $pdo->prepare('DELETE FROM oca_telegram_bot_tags WHERE bot_id = ?')->execute([$botId]);
    }
    $pdo->prepare('DELETE FROM oca_telegram_bot_logs WHERE bot_id = ?')->execute([$botId]);
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcasts')) {
        $pdo->prepare('DELETE FROM oca_telegram_bot_broadcast_recipients WHERE bot_id = ?')->execute([$botId]);
        $pdo->prepare('DELETE FROM oca_telegram_bot_broadcasts WHERE bot_id = ?')->execute([$botId]);
    }
    $pdo->prepare('DELETE FROM oca_telegram_bots WHERE id = ?')->execute([$botId]);
}


function asr_tg_bots_all_light(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, created_by, channel_type, title, bot_username, bot_first_name, telegram_bot_id, vk_group_id, vk_screen_name, status, webhook_url, last_error, last_webhook_at, created_at, updated_at FROM oca_telegram_bots ORDER BY id DESC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_bot_find_light(PDO $pdo, int $botId): ?array {
    if ($botId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT id, created_by, channel_type, title, bot_username, bot_first_name, telegram_bot_id, vk_group_id, vk_screen_name, status, webhook_url, last_error, last_webhook_at, created_at, updated_at FROM oca_telegram_bots WHERE id = ? LIMIT 1");
        $stmt->execute([$botId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function asr_tg_tags_all_light(PDO $pdo, int $botId = 0): array {
    try {
        if ($botId > 0) {
            $stmt = $pdo->prepare('SELECT id, bot_id, name, color, created_at, updated_at FROM oca_telegram_bot_tags WHERE bot_id = ? ORDER BY name ASC');
            $stmt->execute([$botId]);
        } else {
            $stmt = $pdo->query('SELECT t.id, t.bot_id, t.name, t.color, t.created_at, t.updated_at, b.title AS bot_title FROM oca_telegram_bot_tags t LEFT JOIN oca_telegram_bots b ON b.id = t.bot_id ORDER BY t.name ASC');
        }
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_subscribers_fast_where(int $botId = 0, array $filters = []): array {
    $where = [];
    $params = [];
    if ($botId > 0) { $where[] = 's.bot_id = ?'; $params[] = $botId; }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . mb_substr($q, 0, 120, 'UTF-8') . '%';
        $where[] = "(s.username LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CAST(s.telegram_user_id AS CHAR) LIKE ? OR CAST(s.chat_id AS CHAR) LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') { $where[] = 's.status = ?'; $params[] = mb_substr($status, 0, 30, 'UTF-8'); }

    $tagId = (int)($filters['tag_id'] ?? 0);
    if ($tagId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st WHERE st.subscriber_id = s.id AND st.tag_id = ?)';
        $params[] = $tagId;
    }

    // Конструктор сложных условий на странице подписчиков временно ограничен безопасными условиями.
    // Поле dialogs намеренно не обрабатываем здесь: оно требует COUNT по большой таблице сообщений.
    $fields = $filters['filter_field'] ?? [];
    $ops = $filters['filter_op'] ?? [];
    $values = $filters['filter_value'] ?? [];
    if (!is_array($fields)) $fields = [];
    if (!is_array($ops)) $ops = [];
    if (!is_array($values)) $values = [];
    foreach ($fields as $i => $field) {
        $field = trim((string)$field);
        $op = trim((string)($ops[$i] ?? 'contains'));
        $value = trim((string)($values[$i] ?? ''));
        if ($field === '' || $field === 'dialogs') continue;
        if ($op !== 'unknown' && $field !== 'unsubscribed' && $value === '') continue;
        if ($field === 'status') {
            if ($op === 'unknown') $where[] = "(s.status IS NULL OR s.status = '')";
            else { $where[] = $op === 'not' ? 's.status <> ?' : 's.status = ?'; $params[] = mb_substr($value, 0, 30, 'UTF-8'); }
        } elseif ($field === 'unsubscribed') {
            $where[] = $op === 'not' ? "s.status <> 'unsubscribed'" : "s.status = 'unsubscribed'";
        } elseif ($field === 'tag') {
            $id = (int)$value;
            if ($op === 'unknown') $where[] = 'NOT EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st0 WHERE st0.subscriber_id = s.id)';
            elseif ($id > 0) { $where[] = $op === 'not' ? 'NOT EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st1 WHERE st1.subscriber_id = s.id AND st1.tag_id = ?)' : 'EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st1 WHERE st1.subscriber_id = s.id AND st1.tag_id = ?)'; $params[] = $id; }
        } elseif (in_array($field, ['first_seen_at','last_seen_at'], true)) {
            if ($op === 'unknown') $where[] = "s.{$field} IS NULL";
            else {
                $d = mb_substr($value, 0, 10, 'UTF-8');
                if ($d !== '') { $where[] = $op === 'before' ? "s.{$field} < ?" : ($op === 'after' ? "s.{$field} > ?" : ($op === 'not' ? "DATE(s.{$field}) <> ?" : "DATE(s.{$field}) = ?")); $params[] = $d; }
            }
        } elseif (in_array($field, ['name','username','telegram_user_id','chat_id'], true)) {
            $expr = [
                'name' => "CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))",
                'username' => 's.username',
                'telegram_user_id' => 'CAST(s.telegram_user_id AS CHAR)',
                'chat_id' => 'CAST(s.chat_id AS CHAR)',
            ][$field];
            if ($op === 'unknown') $where[] = "({$expr} IS NULL OR {$expr} = '')";
            else { $where[] = $op === 'equals' ? "{$expr} = ?" : ($op === 'not' ? "{$expr} NOT LIKE ?" : "{$expr} LIKE ?"); $params[] = $op === 'equals' ? $value : '%' . $value . '%'; }
        }
    }

    return [$where, $params];
}

function asr_tg_subscribers_count_light(PDO $pdo, int $botId = 0, array $filters = []): int {
    try {
        [$where, $params] = asr_tg_subscribers_fast_where($botId, $filters);
        $sql = 'SELECT COUNT(*) FROM oca_telegram_bot_subscribers s';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function asr_tg_subscribers_search_light(PDO $pdo, int $botId = 0, int $limit = 25, array $filters = [], int $offset = 0): array {
    try {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$where, $params] = asr_tg_subscribers_fast_where($botId, $filters);
        $sql = 'SELECT s.*, b.title AS bot_title, b.bot_username, b.channel_type, b.vk_screen_name, 0 AS messages_count, NULL AS last_message_at FROM oca_telegram_bot_subscribers s JOIN oca_telegram_bots b ON b.id = s.bot_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_subscriber_tags_map_light(PDO $pdo, int $botId = 0, array $subscriberIds = []): array {
    $subscriberIds = array_values(array_filter(array_map('intval', $subscriberIds), static fn($v) => $v > 0));
    if (!$subscriberIds) return [];
    try {
        $params = [];
        $where = 'st.subscriber_id IN (' . implode(',', array_fill(0, count($subscriberIds), '?')) . ')';
        $params = array_merge($params, $subscriberIds);
        $stmt = $pdo->prepare('SELECT st.subscriber_id, t.id, t.name, t.color FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_tags t ON t.id = st.tag_id WHERE ' . $where . ' ORDER BY t.name ASC');
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int)$row['subscriber_id'];
            $map[$sid][] = $row;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function asr_tg_bot_commands_all(PDO $pdo, int $botId): array {
    if ($botId <= 0) return [];
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_commands WHERE bot_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_bot_commands_replace(PDO $pdo, int $botId, array $commands): void {
    if ($botId <= 0) throw new InvalidArgumentException('Канал не найден.');
    asr_tg_repository_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM oca_telegram_bot_commands WHERE bot_id = ?')->execute([$botId]);
        if ($commands) {
            $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_commands (bot_id, command, description, scenario_id, step_id, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
            foreach ($commands as $item) {
                $stmt->execute([
                    $botId,
                    $item['command'],
                    $item['description'],
                    $item['scenario_id'] ?? null,
                    $item['step_id'] ?? null,
                    (int)($item['sort_order'] ?? 100),
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_subscriber_upsert(PDO $pdo, int $botId, array $from, int|string $chatId): int {
    $telegramUserId = (int)($from['id'] ?? 0);
    if ($botId <= 0 || $telegramUserId <= 0) return 0;
    $chatIdInt = (int)$chatId;
    $username = mb_substr((string)($from['username'] ?? ''), 0, 190) ?: null;
    $firstName = mb_substr((string)($from['first_name'] ?? ''), 0, 190) ?: null;
    $lastName = mb_substr((string)($from['last_name'] ?? ''), 0, 190) ?: null;
    $languageCode = mb_substr((string)($from['language_code'] ?? ''), 0, 20) ?: null;
    $isBot = !empty($from['is_bot']) ? 1 : 0;

    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_subscribers (bot_id, telegram_user_id, chat_id, username, first_name, last_name, language_code, is_bot, status, first_seen_at, last_seen_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), language_code = VALUES(language_code), is_bot = VALUES(is_bot), status = IF(status = 'blocked', 'blocked', 'active'), last_seen_at = NOW(), updated_at = NOW()");
    $stmt->execute([
        $botId,
        $telegramUserId,
        $chatIdInt,
        $username,
        $firstName,
        $lastName,
        $languageCode,
        $isBot,
    ]);

    // Имя, username и базовая Telegram-идентификация описывают человека, а не отдельную подписку.
    // Поэтому держим эти поля едиными во всех каналах одного telegram_user_id.
    // Канальный статус, даты подписки и история диалога остаются отдельными по каждой подписке.
    try {
        $syncStmt = $pdo->prepare('UPDATE oca_telegram_bot_subscribers
            SET chat_id = ?, username = ?, first_name = ?, last_name = ?, language_code = ?, is_bot = ?, updated_at = NOW()
            WHERE telegram_user_id = ?');
        $syncStmt->execute([$chatIdInt, $username, $firstName, $lastName, $languageCode, $isBot, $telegramUserId]);
    } catch (Throwable $e) {
        // Синхронизация общих полей не должна блокировать приём сообщения.
    }

    $stmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND telegram_user_id = ? LIMIT 1');
    $stmt->execute([$botId, $telegramUserId]);
    return (int)$stmt->fetchColumn();
}

function asr_tg_message_add(PDO $pdo, int $botId, ?int $subscriberId, string $direction, string $messageType, ?string $text, ?int $telegramMessageId, array $payload = []): void {
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_messages (bot_id, subscriber_id, direction, message_type, message_text, telegram_message_id, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$botId, $subscriberId ?: null, $direction, mb_substr($messageType, 0, 40), $text, $telegramMessageId, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    $messageId = (int)$pdo->lastInsertId();
    if ($subscriberId && $messageId > 0 && asr_tg_dialog_should_touch($direction, $payload)) {
        asr_tg_dialog_touch($pdo, $botId, (int)$subscriberId, $messageId, $direction);
    }
}

function asr_tg_tag_normalize_name(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?: '');
    return mb_substr($name, 0, 80, 'UTF-8');
}

function asr_tg_tag_normalize_color(string $color): string {
    $color = trim($color);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return '#FFF4E8';
    }
    return strtoupper($color);
}

function asr_tg_tags_all(PDO $pdo, int $botId = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    if ($botId > 0) {
        $stmt = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM oca_telegram_bot_subscriber_tags st WHERE st.tag_id = t.id) AS subscribers_count FROM oca_telegram_bot_tags t WHERE t.bot_id = ? ORDER BY t.name ASC');
        $stmt->execute([$botId]);
    } else {
        $stmt = $pdo->query('SELECT t.*, b.title AS bot_title, (SELECT COUNT(*) FROM oca_telegram_bot_subscriber_tags st WHERE st.tag_id = t.id) AS subscribers_count FROM oca_telegram_bot_tags t LEFT JOIN oca_telegram_bots b ON b.id = t.bot_id ORDER BY t.name ASC');
    }
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_tag_find(PDO $pdo, int $tagId): ?array {
    if ($tagId <= 0) return null;
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_tags WHERE id = ? LIMIT 1');
    $stmt->execute([$tagId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_tg_tag_create(PDO $pdo, int $botId, string $name, string $color = '#FFF4E8'): int {
    asr_tg_repository_ensure_schema($pdo);
    $name = asr_tg_tag_normalize_name($name);
    $botId = max(0, $botId);
    if ($name === '') throw new InvalidArgumentException('Укажите название тега.');
    $color = '#F3F4F6';
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_tags (bot_id, name, color, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE color = VALUES(color), updated_at = NOW()');
    $stmt->execute([$botId, $name, $color]);
    if ((int)$pdo->lastInsertId() > 0) return (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_tags WHERE bot_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$botId, $name]);
    return (int)$stmt->fetchColumn();
}

function asr_tg_tag_delete(PDO $pdo, int $tagId): void {
    if ($tagId <= 0) return;
    asr_tg_repository_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_tags WHERE tag_id = ?')->execute([$tagId]);
    $pdo->prepare('DELETE FROM oca_telegram_bot_tags WHERE id = ?')->execute([$tagId]);
}

function asr_tg_subscriber_tag_add(PDO $pdo, int $subscriberId, int $tagId): void {
    if ($subscriberId <= 0 || $tagId <= 0) return;
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_tags WHERE id = ? LIMIT 1');
    $stmt->execute([$tagId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Тег не найден.');
    $stmt = $pdo->prepare('INSERT IGNORE INTO oca_telegram_bot_subscriber_tags (subscriber_id, tag_id, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$subscriberId, $tagId]);
}

function asr_tg_subscriber_tag_remove(PDO $pdo, int $subscriberId, int $tagId): void {
    if ($subscriberId <= 0 || $tagId <= 0) return;
    asr_tg_repository_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_tags WHERE subscriber_id = ? AND tag_id = ?')->execute([$subscriberId, $tagId]);
}

function asr_tg_subscriber_tags_map(PDO $pdo, int $botId = 0, array $subscriberIds = []): array {
    asr_tg_repository_ensure_schema($pdo);
    $params = [];
    $where = '1=1';
    $subscriberIds = array_values(array_filter(array_map('intval', $subscriberIds), static fn($v) => $v > 0));
    if ($subscriberIds) {
        $where .= ' AND st.subscriber_id IN (' . implode(',', array_fill(0, count($subscriberIds), '?')) . ')';
        $params = array_merge($params, $subscriberIds);
    }
    $stmt = $pdo->prepare('SELECT st.subscriber_id, t.id, t.name, t.color FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_tags t ON t.id = st.tag_id WHERE ' . $where . ' ORDER BY t.name ASC');
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)$row['subscriber_id'];
        $map[$sid][] = $row;
    }
    return $map;
}

function asr_tg_subscribers_filter_sql(int $botId = 0, array $filters = []): array {
    $params = [];
    $where = [];
    $joins = [];
    if ($botId > 0) { $where[] = 's.bot_id = ?'; $params[] = $botId; }

    $conditionFields = $filters['filter_field'] ?? [];
    $conditionOps = $filters['filter_op'] ?? [];
    $conditionValues = $filters['filter_value'] ?? [];
    $conditionLogics = $filters['filter_logic'] ?? [];
    if (!is_array($conditionFields)) $conditionFields = [];
    if (!is_array($conditionOps)) $conditionOps = [];
    if (!is_array($conditionValues)) $conditionValues = [];
    if (!is_array($conditionLogics)) $conditionLogics = [];

    $tagJoinIndex = 0;
    $conditionSql = [];
    $conditionParams = [];
    foreach ($conditionFields as $idx => $field) {
        $field = trim((string)$field);
        $op = trim((string)($conditionOps[$idx] ?? 'contains'));
        $value = trim((string)($conditionValues[$idx] ?? ''));
        if ($field === '') continue;

        $sqlPart = '';
        $partParams = [];

        if ($field === 'status') {
            if ($op === 'unknown') { $sqlPart = "(s.status IS NULL OR s.status = '')"; }
            elseif ($value !== '') {
                $sqlPart = $op === 'not' ? 's.status <> ?' : 's.status = ?';
                $partParams[] = mb_substr($value, 0, 30);
            }
        } elseif ($field === 'unsubscribed') {
            $sqlPart = $op === 'not' ? "s.status <> 'unsubscribed'" : "s.status = 'unsubscribed'";
        } elseif ($field === 'tag') {
            $tagId = (int)$value;
            if ($op === 'unknown') {
                $sqlPart = 'NOT EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st0 WHERE st0.subscriber_id = s.id)';
            } elseif ($tagId > 0) {
                $alias = 'stf' . (++$tagJoinIndex);
                if ($op === 'not') {
                    $sqlPart = "NOT EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags {$alias} WHERE {$alias}.subscriber_id = s.id AND {$alias}.tag_id = ?)";
                } else {
                    $sqlPart = "EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags {$alias} WHERE {$alias}.subscriber_id = s.id AND {$alias}.tag_id = ?)";
                }
                $partParams[] = $tagId;
            }
        } elseif (in_array($field, ['first_seen_at','last_seen_at'], true)) {
            if ($op === 'unknown') { $sqlPart = "s.{$field} IS NULL"; }
            elseif ($value !== '') {
                $dateValue = mb_substr($value, 0, 19);
                if ($op === 'not') { $sqlPart = "DATE(s.{$field}) <> ?"; $partParams[] = mb_substr($dateValue, 0, 10); }
                elseif ($op === 'before') { $sqlPart = "DATE(s.{$field}) < ?"; $partParams[] = mb_substr($dateValue, 0, 10); }
                elseif ($op === 'after') { $sqlPart = "DATE(s.{$field}) > ?"; $partParams[] = mb_substr($dateValue, 0, 10); }
                else { $sqlPart = "DATE(s.{$field}) = ?"; $partParams[] = mb_substr($dateValue, 0, 10); }
            }
        } elseif ($field === 'dialogs') {
            if ($value !== '' && is_numeric($value)) {
                $num = max(0, (int)$value);
                $expr = '(SELECT COUNT(*) FROM oca_telegram_bot_messages mx WHERE mx.subscriber_id = s.id)';
                if ($op === 'gt') $sqlPart = $expr . ' > ?';
                elseif ($op === 'lt') $sqlPart = $expr . ' < ?';
                elseif ($op === 'not') $sqlPart = $expr . ' <> ?';
                else $sqlPart = $expr . ' = ?';
                $partParams[] = $num;
            }
        } else {
            $fieldMap = [
                'name' => "CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))",
                'username' => 's.username',
                'telegram_user_id' => 'CAST(s.telegram_user_id AS CHAR)',
                'chat_id' => 'CAST(s.chat_id AS CHAR)',
            ];
            if (isset($fieldMap[$field])) {
                $expr = $fieldMap[$field];
                if ($op === 'unknown') { $sqlPart = "({$expr} IS NULL OR {$expr} = '')"; }
                elseif ($value !== '') {
                    if ($op === 'equals') { $sqlPart = "{$expr} = ?"; $partParams[] = $value; }
                    elseif ($op === 'not') { $sqlPart = "{$expr} NOT LIKE ?"; $partParams[] = '%' . $value . '%'; }
                    else { $sqlPart = "{$expr} LIKE ?"; $partParams[] = '%' . $value . '%'; }
                }
            }
        }

        if ($sqlPart === '') continue;
        $logic = strtolower(trim((string)($conditionLogics[$idx] ?? 'and')));
        $conditionSql[] = [
            'logic' => $logic === 'or' ? 'OR' : 'AND',
            'sql' => '(' . $sqlPart . ')',
        ];
        $conditionParams = array_merge($conditionParams, $partParams);
    }

    if ($conditionSql) {
        $group = '';
        foreach ($conditionSql as $idx => $item) {
            $group .= ($idx === 0 ? '' : ' ' . $item['logic'] . ' ') . $item['sql'];
        }
        $where[] = '(' . $group . ')';
        $params = array_merge($params, $conditionParams);
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') { $where[] = 's.status = ?'; $params[] = mb_substr($status, 0, 30); }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(s.username LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CAST(s.telegram_user_id AS CHAR) LIKE ? OR CAST(s.chat_id AS CHAR) LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    $tagId = (int)($filters['tag_id'] ?? 0);
    if ($tagId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags st WHERE st.subscriber_id = s.id AND st.tag_id = ?)';
        $params[] = $tagId;
    }

    return [implode(' ', $joins), $where, $params];
}

function asr_tg_subscribers_search(PDO $pdo, int $botId = 0, int $limit = 100, array $filters = [], int $offset = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(300, $limit));
    $offset = max(0, $offset);
    [$join, $where, $params] = asr_tg_subscribers_filter_sql($botId, $filters);
    $sql = 'SELECT s.*, b.title AS bot_title, b.bot_username, b.channel_type, b.vk_screen_name, (SELECT COUNT(*) FROM oca_telegram_bot_messages m WHERE m.subscriber_id = s.id) AS messages_count, (SELECT MAX(m2.created_at) FROM oca_telegram_bot_messages m2 WHERE m2.subscriber_id = s.id) AS last_message_at FROM oca_telegram_bot_subscribers s JOIN oca_telegram_bots b ON b.id = s.bot_id ' . $join;
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY s.first_seen_at DESC, s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_subscribers_count(PDO $pdo, int $botId = 0, array $filters = []): int {
    asr_tg_repository_ensure_schema($pdo);
    [$join, $where, $params] = asr_tg_subscribers_filter_sql($botId, $filters);
    $sql = 'SELECT COUNT(*) FROM oca_telegram_bot_subscribers s JOIN oca_telegram_bots b ON b.id = s.bot_id ' . $join;
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}


function asr_tg_subscribers_delete_many(PDO $pdo, int $botId, array $subscriberIds): int {
    $subscriberIds = array_values(array_unique(array_filter(array_map('intval', $subscriberIds), static fn($v) => $v > 0)));
    if (!$subscriberIds) return 0;
    $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));
    if ($botId > 0) {
        $params = array_merge([$botId], $subscriberIds);
        $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND id IN ($placeholders)");
    } else {
        $params = $subscriberIds;
        $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_subscribers WHERE id IN ($placeholders)");
    }
    $stmt->execute($params);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$validIds) return 0;
    $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    $pdo->prepare("DELETE FROM oca_telegram_bot_subscriber_tags WHERE subscriber_id IN ($validPlaceholders)")->execute($validIds);
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_subscriber_custom_values')) {
        $pdo->prepare("DELETE FROM oca_telegram_bot_subscriber_custom_values WHERE subscriber_id IN ($validPlaceholders)")->execute($validIds);
    }
    $pdo->prepare("DELETE FROM oca_telegram_bot_messages WHERE subscriber_id IN ($validPlaceholders)")->execute($validIds);
    if ($botId > 0) {
        $stmt = $pdo->prepare("DELETE FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND id IN ($validPlaceholders)");
        $stmt->execute(array_merge([$botId], $validIds));
    } else {
        $stmt = $pdo->prepare("DELETE FROM oca_telegram_bot_subscribers WHERE id IN ($validPlaceholders)");
        $stmt->execute($validIds);
    }
    return $stmt->rowCount();
}


function asr_tg_subscriber_find(PDO $pdo, int $subscriberId, int $botId = 0): ?array {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) return null;
    $sql = 'SELECT s.*, b.title AS bot_title, b.bot_username, b.channel_type, b.vk_screen_name, b.bot_token_encrypted
        FROM oca_telegram_bot_subscribers s
        JOIN oca_telegram_bots b ON b.id = s.bot_id
        WHERE s.id = ?';
    $params = [$subscriberId];
    if ($botId > 0) {
        $sql .= ' AND s.bot_id = ?';
        $params[] = $botId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function asr_tg_dialog_resolve_subscriber(PDO $pdo, int $candidateId, int $botId = 0): ?array {
    asr_tg_repository_ensure_schema($pdo);
    if ($candidateId <= 0) return null;

    // Нормальный путь: в URL действительно subscriber_id.
    $subscriber = asr_tg_subscriber_find($pdo, $candidateId, $botId);
    if ($subscriber) return $subscriber;
    $subscriber = asr_tg_subscriber_find($pdo, $candidateId, 0);
    if ($subscriber) return $subscriber;

    // Защитный путь: в старых ссылках/JS в параметр subscriber_id мог попасть id диалога.
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_dialogs')) {
        $sql = 'SELECT subscriber_id, bot_id FROM oca_telegram_bot_dialogs WHERE id = ?';
        $params = [$candidateId];
        if ($botId > 0) {
            $sql .= ' AND bot_id = ?';
            $params[] = $botId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dialog = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

        if (!$dialog && $botId > 0) {
            $stmt = $pdo->prepare('SELECT subscriber_id, bot_id FROM oca_telegram_bot_dialogs WHERE id = ? LIMIT 1');
            $stmt->execute([$candidateId]);
            $dialog = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        }

        $realSubscriberId = (int)($dialog['subscriber_id'] ?? 0);
        $realBotId = (int)($dialog['bot_id'] ?? 0);
        if ($realSubscriberId > 0) {
            $subscriber = asr_tg_subscriber_find($pdo, $realSubscriberId, $realBotId > 0 ? $realBotId : 0);
            if ($subscriber) {
                $subscriber['_resolved_from_dialog_id'] = $candidateId;
                return $subscriber;
            }
        }
    }

    // Последний fallback: иногда открытая страница уже показывает сообщения, но query string
    // несёт устаревший/чужой subscriber_id. Тогда источником правды берём последнее входящее
    // сообщение текущего канала и восстанавливаем подписчика через него.
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')) {
        $messageCandidates = [];

        try {
            $params = [$candidateId, $candidateId];
            $where = "(subscriber_id = ? OR id = ?)";
            if ($botId > 0) {
                $where .= ' AND bot_id = ?';
                $params[] = $botId;
            }
            $stmt = $pdo->prepare("SELECT id, bot_id, subscriber_id, direction, message_type, message_text, created_at FROM oca_telegram_bot_messages WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 5");
            $stmt->execute($params);
            $messageCandidates = array_merge($messageCandidates, $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []);
        } catch (Throwable $e) {
            $messageCandidates = [];
        }

        if (!$messageCandidates && $botId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id, bot_id, subscriber_id, direction, message_type, message_text, created_at FROM oca_telegram_bot_messages WHERE direction = 'in' AND bot_id = ? ORDER BY created_at DESC, id DESC LIMIT 5");
                $stmt->execute([$botId]);
                $messageCandidates = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $messageCandidates = [];
            }
        }

        foreach ($messageCandidates as $message) {
            $realSubscriberId = (int)($message['subscriber_id'] ?? 0);
            $realBotId = (int)($message['bot_id'] ?? 0);
            if ($realSubscriberId <= 0) continue;
            $subscriber = asr_tg_subscriber_find($pdo, $realSubscriberId, $realBotId > 0 ? $realBotId : 0);
            if ($subscriber) {
                $subscriber['_resolved_from_last_incoming'] = (int)($message['id'] ?? 0);
                return $subscriber;
            }
        }
    }

    return null;
}

function asr_tg_subscriber_channel_memberships(PDO $pdo, array $subscriber): array {
    asr_tg_repository_ensure_schema($pdo);
    $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
    if ($telegramUserId <= 0) return [];

    $sql = "SELECT
            s.id,
            s.bot_id,
            s.status,
            s.username,
            s.first_seen_at,
            s.last_seen_at,
            b.title AS bot_title,
            b.bot_username,
            b.channel_type,
            b.vk_screen_name,
            d.id AS dialog_id,
            d.status AS dialog_status,
            d.unread_count,
            d.last_message_at
        FROM oca_telegram_bot_subscribers s
        JOIN oca_telegram_bots b ON b.id = s.bot_id
        LEFT JOIN oca_telegram_bot_dialogs d ON d.subscriber_id = s.id
        WHERE s.telegram_user_id = ?
        ORDER BY b.title ASC, s.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$telegramUserId]);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_subscriber_profile_update(PDO $pdo, int $subscriberId, int $botId, array $data): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0 || $botId <= 0) throw new InvalidArgumentException('Подписчик не найден.');
    $allowedStatuses = ['active','inactive','unsubscribed','blocked'];
    $status = (string)($data['status'] ?? 'active');
    if (!in_array($status, $allowedStatuses, true)) $status = 'active';
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email указан некорректно.');

    $firstName = mb_substr(trim((string)($data['first_name'] ?? '')), 0, 190) ?: null;
    $lastName = mb_substr(trim((string)($data['last_name'] ?? '')), 0, 190) ?: null;
    $phone = mb_substr(trim((string)($data['phone'] ?? '')), 0, 80) ?: null;
    $emailValue = mb_substr($email, 0, 190) ?: null;
    $note = trim((string)($data['admin_note'] ?? '')) ?: null;

    $current = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$current) throw new InvalidArgumentException('Подписчик не найден.');
    $telegramUserId = (int)($current['telegram_user_id'] ?? 0);

    if ($telegramUserId > 0) {
        // Контактная карточка общая для одного Telegram-пользователя во всех подключённых ботах.
        // Статус оставляем отдельным по каждому каналу, чтобы спам/блокировка одного бота не ломала другие.
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_subscribers
            SET first_name = ?, last_name = ?, phone = ?, email = ?, admin_note = ?, updated_at = NOW()
            WHERE telegram_user_id = ?');
        $stmt->execute([$firstName, $lastName, $phone, $emailValue, $note, $telegramUserId]);

        $statusStmt = $pdo->prepare('UPDATE oca_telegram_bot_subscribers SET status = ?, updated_at = NOW() WHERE id = ? AND bot_id = ?');
        $statusStmt->execute([$status, $subscriberId, $botId]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_subscribers
        SET first_name = ?, last_name = ?, phone = ?, email = ?, admin_note = ?, status = ?, updated_at = NOW()
        WHERE id = ? AND bot_id = ?');
    $stmt->execute([$firstName, $lastName, $phone, $emailValue, $note, $status, $subscriberId, $botId]);
}

function asr_tg_subscriber_messages(PDO $pdo, int $subscriberId, int $limit = 200): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_messages WHERE subscriber_id = ? ORDER BY id ASC LIMIT ' . $limit);
    $stmt->execute([$subscriberId]);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_subscribers_recent(PDO $pdo, int $botId = 0, int $limit = 50): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    if ($botId > 0) {
        $stmt = $pdo->prepare('SELECT s.*, b.title AS bot_title, b.bot_username FROM oca_telegram_bot_subscribers s JOIN oca_telegram_bots b ON b.id = s.bot_id WHERE s.bot_id = ? ORDER BY s.last_seen_at DESC LIMIT ' . $limit);
        $stmt->execute([$botId]);
    } else {
        $stmt = $pdo->query('SELECT s.*, b.title AS bot_title, b.bot_username FROM oca_telegram_bot_subscribers s JOIN oca_telegram_bots b ON b.id = s.bot_id ORDER BY s.last_seen_at DESC LIMIT ' . $limit);
    }
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_logs_recent(PDO $pdo, int $botId = 0, int $limit = 80): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(300, $limit));
    if ($botId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_logs WHERE bot_id = ? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$botId]);
    } else {
        $stmt = $pdo->query('SELECT * FROM oca_telegram_bot_logs ORDER BY id DESC LIMIT ' . $limit);
    }
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}





function asr_tg_dialog_visible_payload_allowed(string $direction, array $payload = []): bool {
    if ($direction !== 'out') return true;
    foreach (['broadcast_id','flow_id','scenario_id'] as $key) {
        if (array_key_exists($key, $payload)) return false;
    }
    return true;
}

function asr_tg_dialog_should_touch(string $direction, array $payload = []): bool {
    if (!asr_tg_dialog_visible_payload_allowed($direction, $payload)) return false;
    if ($direction === 'out') {
        foreach (['dialog_auto_reply','dialog_system_reply'] as $key) {
            if (array_key_exists($key, $payload)) return false;
        }
    }
    return true;
}

function asr_tg_current_user_id(): int {
    return max(0, (int)($_SESSION['user_id'] ?? 0));
}

function asr_tg_user_label_by_id(PDO $pdo, int $userId): string {
    if ($userId <= 0) return '';
    try {
        $stmt = $pdo->prepare('SELECT * FROM `oca_users` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        foreach (['full_name','name'] as $column) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') return $value;
        }
        $firstName = trim((string)($row['first_name'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        if ($name !== '') return $name;
        $username = trim((string)($row['username'] ?? $row['telegram_username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') return $email;
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

function asr_tg_current_user_label(PDO $pdo): string {
    $userId = asr_tg_current_user_id();
    $label = $userId > 0 ? asr_tg_user_label_by_id($pdo, $userId) : '';
    return $label !== '' ? $label : 'Сотрудник';
}

function asr_tg_dialog_system_event_add(PDO $pdo, int $botId, int $subscriberId, string $text, string $event = '', array $payload = []): void {
    if ($botId <= 0 || $subscriberId <= 0) return;
    $text = trim($text);
    if ($text === '') return;
    $basePayload = [
        'dialog_event' => true,
        'dialog_system_reply' => true,
        'event' => $event,
        'actor_user_id' => asr_tg_current_user_id(),
    ];
    asr_tg_message_add($pdo, $botId, $subscriberId, 'out', 'system', $text, null, array_merge($basePayload, $payload));
}

function asr_tg_dialog_touch(PDO $pdo, int $botId, int $subscriberId, int $messageId, string $direction): void {
    if ($botId <= 0 || $subscriberId <= 0 || $messageId <= 0) return;
    $direction = $direction === 'out' ? 'out' : 'in';
    $currentUserId = asr_tg_current_user_id();
    $settings = $direction === 'in' ? asr_tg_dialog_settings_get($pdo, $botId) : [];
    $autoClose = $direction === 'in' && !empty($settings['auto_close_incoming']);

    $stmt = $pdo->prepare('SELECT id, status, assigned_user_id, unread_count FROM oca_telegram_bot_dialogs WHERE bot_id = ? AND subscriber_id = ? LIMIT 1');
    $stmt->execute([$botId, $subscriberId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $status = $direction === 'out' ? 'assigned' : ($autoClose ? 'closed' : 'new');
        $assignedUserId = $direction === 'out' && $currentUserId > 0 ? $currentUserId : null;
        $unread = $direction === 'in' && !$autoClose ? 1 : 0;
        $readAt = $direction === 'out' || $autoClose ? date('Y-m-d H:i:s') : null;
        $closedAt = $status === 'closed' ? date('Y-m-d H:i:s') : null;
        $insert = $pdo->prepare('INSERT INTO oca_telegram_bot_dialogs (bot_id, subscriber_id, status, assigned_user_id, unread_count, last_message_id, last_message_at, last_direction, read_at, closed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())');
        $insert->execute([$botId, $subscriberId, $status, $assignedUserId, $unread, $messageId, $direction, $readAt, $closedAt]);
        return;
    }

    $status = (string)($existing['status'] ?? 'new');
    $assignedUserId = isset($existing['assigned_user_id']) ? (int)$existing['assigned_user_id'] : null;
    $unread = (int)($existing['unread_count'] ?? 0);
    $closedAtSql = 'closed_at';
    $spamAtSql = 'spam_at';
    $readAtSql = 'read_at';

    if ($direction === 'in') {
        if ($status !== 'spam') {
            $status = $autoClose ? 'closed' : (in_array($status, ['closed'], true) ? 'new' : $status);
        }
        $unread = $status === 'spam' || $autoClose ? 0 : $unread + 1;
        if ($autoClose && $status !== 'spam') {
            $closedAtSql = 'NOW()';
            $readAtSql = 'NOW()';
        }
    } else {
        if ($status !== 'spam') {
            $status = 'assigned';
            if ($currentUserId > 0) $assignedUserId = $currentUserId;
            $unread = 0;
            $readAtSql = 'NOW()';
            $closedAtSql = 'NULL';
        }
    }

    $sql = "UPDATE oca_telegram_bot_dialogs SET bot_id = ?, status = ?, assigned_user_id = ?, unread_count = ?, last_message_id = ?, last_message_at = NOW(), last_direction = ?, read_at = {$readAtSql}, closed_at = {$closedAtSql}, spam_at = {$spamAtSql}, updated_at = NOW() WHERE bot_id = ? AND subscriber_id = ?";
    $upd = $pdo->prepare($sql);
    $upd->execute([$botId, $status, $assignedUserId ?: null, $unread, $messageId, $direction, $botId, $subscriberId]);
}

function asr_tg_dialogs_backfill(PDO $pdo): void {
    $visible = asr_tg_dialog_visible_message_sql('m');
    $sql = "INSERT INTO oca_telegram_bot_dialogs (bot_id, subscriber_id, status, assigned_user_id, unread_count, last_message_id, last_message_at, last_direction, read_at, created_at, updated_at)
        SELECT lm.bot_id, lm.subscriber_id, 'new', NULL, IF(lm.direction = 'in', 1, 0), lm.id, lm.created_at, lm.direction, IF(lm.direction = 'out', lm.created_at, NULL), NOW(), NOW()
        FROM oca_telegram_bot_messages lm
        JOIN (
            SELECT m.bot_id, m.subscriber_id, MAX(m.id) AS last_message_id
            FROM oca_telegram_bot_messages m
            WHERE m.subscriber_id IS NOT NULL
              AND {$visible}
              AND EXISTS (SELECT 1 FROM oca_telegram_bot_messages mi WHERE mi.bot_id = m.bot_id AND mi.subscriber_id = m.subscriber_id AND mi.direction = 'in')
            GROUP BY m.bot_id, m.subscriber_id
        ) x ON x.last_message_id = lm.id AND x.bot_id = lm.bot_id AND x.subscriber_id = lm.subscriber_id
        LEFT JOIN oca_telegram_bot_dialogs d ON d.bot_id = lm.bot_id AND d.subscriber_id = lm.subscriber_id
        WHERE d.id IS NULL";
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // На старой таблице с уникальным индексом только по subscriber_id вставка может упереться в индекс.
        // В этом случае существующие диалоги не ломаем, а новые будут создаваться через asr_tg_dialog_touch().
    }
}

function asr_tg_dialog_mark_read(PDO $pdo, int $subscriberId, int $botId = 0): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) return;
    $params = [$subscriberId];
    $where = 'subscriber_id = ?';
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET unread_count = 0, read_at = NOW(), updated_at = NOW() WHERE {$where}");
    $stmt->execute($params);
}

function asr_tg_dialog_mark_unread(PDO $pdo, int $subscriberId, int $botId = 0): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) return;
    $params = [$subscriberId];
    $where = "subscriber_id = ? AND status NOT IN ('closed','spam')";
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET unread_count = GREATEST(unread_count, 1), read_at = NULL, updated_at = NOW() WHERE {$where}");
    $stmt->execute($params);
}

function asr_tg_dialog_set_read_state(PDO $pdo, int $subscriberId, bool $isRead, int $botId = 0): void {
    if ($isRead) {
        asr_tg_dialog_mark_read($pdo, $subscriberId, $botId);
        return;
    }
    asr_tg_dialog_mark_unread($pdo, $subscriberId, $botId);
}

function asr_tg_dialog_assignable_users(PDO $pdo): array {
    asr_tg_repository_ensure_schema($pdo);

    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote('oca_users'));
        if (!$stmt || !$stmt->fetchColumn()) return [];
    } catch (Throwable $e) {
        return [];
    }

    $availableColumns = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `oca_users`');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $columnRow) {
            $field = (string)($columnRow['Field'] ?? '');
            if ($field !== '') $availableColumns[$field] = true;
        }
    } catch (Throwable $e) {
        return [];
    }

    if (empty($availableColumns['id'])) return [];

    $wanted = ['id', 'full_name', 'name', 'first_name', 'last_name', 'username', 'email', 'role', 'is_active', 'archived_at', 'connect_to_dialogs', 'telegram_username'];
    $select = [];
    foreach ($wanted as $column) {
        if (!empty($availableColumns[$column])) $select[] = '`' . str_replace('`', '``', $column) . '`';
    }
    if (!$select) return [];

    $queries = [];
    if (!empty($availableColumns['connect_to_dialogs'])) {
        $queries[] = 'SELECT ' . implode(',', $select) . ' FROM `oca_users` WHERE `connect_to_dialogs` = 1 ORDER BY `id` ASC';
        $queries[] = 'SELECT ' . implode(',', $select) . ' FROM `oca_users` WHERE CAST(`connect_to_dialogs` AS UNSIGNED) = 1 ORDER BY `id` ASC';
    }
    // Последний fallback нужен только чтобы select не был пустым из-за отличий старой базы.
    $queries[] = 'SELECT ' . implode(',', $select) . ' FROM `oca_users` ORDER BY `id` ASC';

    $rows = [];
    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $rows = [];
        }
        if ($rows) break;
    }

    foreach ($rows as &$row) {
        foreach (['full_name', 'name', 'first_name', 'last_name', 'username', 'email', 'role', 'is_active', 'archived_at', 'connect_to_dialogs', 'telegram_username'] as $column) {
            if (!array_key_exists($column, $row)) $row[$column] = '';
        }
    }
    unset($row);

    return $rows;
}

function asr_tg_dialog_assignee_label(array $row): string {
    $name = trim((string)($row['assigned_full_name'] ?? ''));
    if ($name !== '') return $name;
    $name = trim((string)($row['assigned_name'] ?? ''));
    if ($name !== '') return $name;
    $firstName = trim((string)($row['assigned_first_name'] ?? ''));
    $lastName = trim((string)($row['assigned_last_name'] ?? ''));
    $name = trim($firstName . ' ' . $lastName);
    if ($name !== '') return $name;
    $username = trim((string)($row['assigned_username'] ?? ''));
    if ($username !== '') return '@' . ltrim($username, '@');
    $telegramUsername = trim((string)($row['assigned_telegram_username'] ?? ''));
    if ($telegramUsername !== '') return '@' . ltrim($telegramUsername, '@');
    $email = trim((string)($row['assigned_email'] ?? ''));
    if ($email !== '') return $email;
    $id = (int)($row['assigned_user_id'] ?? 0);
    return $id > 0 ? 'Сотрудник #' . $id : '';
}

function asr_tg_dialog_assign(PDO $pdo, int $subscriberId, int $assignedUserId, int $botId = 0): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) throw new InvalidArgumentException('Диалог не найден.');

    $paramsTail = [$subscriberId];
    $where = 'subscriber_id = ?';
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $paramsTail[] = $botId;
    }

    if ($assignedUserId <= 0) {
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = 'new', assigned_user_id = NULL, updated_at = NOW() WHERE {$where}");
        $stmt->execute($paramsTail);
        if ($botId > 0) {
            asr_tg_dialog_system_event_add($pdo, $botId, $subscriberId, asr_tg_current_user_label($pdo) . ' снял(а) назначение с диалога', 'dialog_unassigned');
        }
        return;
    }

    // Диалог хранится как assigned; «Мои» и «Назначенные» — это представления по assigned_user_id.
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = 'assigned', assigned_user_id = ?, updated_at = NOW() WHERE {$where}");
    $stmt->execute(array_merge([$assignedUserId], $paramsTail));
    if ($botId > 0) {
        $actor = asr_tg_current_user_label($pdo);
        $assignee = asr_tg_user_label_by_id($pdo, $assignedUserId);
        if ($assignee === '') $assignee = 'сотрудника #' . $assignedUserId;
        $suffix = $assignedUserId === asr_tg_current_user_id() ? 'на себя' : 'на ' . $assignee;
        asr_tg_dialog_system_event_add($pdo, $botId, $subscriberId, $actor . ' назначил(а) диалог ' . $suffix, 'dialog_assigned', ['assigned_user_id' => $assignedUserId]);
    }
}

function asr_tg_dialog_set_status(PDO $pdo, int $subscriberId, string $status, ?int $assignedUserId = null, int $botId = 0): void {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) throw new InvalidArgumentException('Диалог не найден.');
    $allowed = ['new','mine','assigned','closed','spam'];
    if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('Некорректный статус диалога.');
    if ($status === 'mine') {
        $status = 'assigned';
        $assignedUserId = asr_tg_current_user_id() ?: $assignedUserId;
    }
    if ($status === 'new') $assignedUserId = null;
    $closedSql = $status === 'closed' ? 'NOW()' : 'NULL';
    $spamSql = $status === 'spam' ? 'NOW()' : 'NULL';
    $readSql = in_array($status, ['closed','spam'], true) ? 'NOW()' : 'read_at';
    $unreadSql = in_array($status, ['closed','spam'], true) ? '0' : 'unread_count';
    $where = 'subscriber_id = ?';
    $params = [$status, $assignedUserId ?: null, $subscriberId];
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = ?, assigned_user_id = ?, unread_count = {$unreadSql}, read_at = {$readSql}, closed_at = {$closedSql}, spam_at = {$spamSql}, updated_at = NOW() WHERE {$where}");
    $stmt->execute($params);

    if ($botId > 0) {
        $actor = asr_tg_current_user_label($pdo);
        $eventText = '';
        $eventKey = '';
        if ($status === 'closed') {
            $eventText = $actor . ' закрыл(а) диалог';
            $eventKey = 'dialog_closed';
        } elseif ($status === 'new') {
            $eventText = $actor . ' открыл(а) диалог';
            $eventKey = 'dialog_opened';
        } elseif ($status === 'spam') {
            $eventText = $actor . ' перенёс(ла) диалог в спам';
            $eventKey = 'dialog_spam';
        } elseif ($status === 'assigned' && $assignedUserId) {
            $eventText = $actor . ' взял(а) диалог в работу';
            $eventKey = 'dialog_taken';
        }
        if ($eventText !== '') {
            asr_tg_dialog_system_event_add($pdo, $botId, $subscriberId, $eventText, $eventKey, ['dialog_status' => $status]);
        }
    }

    if ($status === 'spam') {
        asr_tg_subscriber_mark_status($pdo, $subscriberId, 'blocked');
    } elseif ($status === 'new') {
        asr_tg_subscriber_mark_status($pdo, $subscriberId, 'active');
    }
}

function asr_tg_dialog_close_all(PDO $pdo, int $botId = 0): int {
    asr_tg_repository_ensure_schema($pdo);
    $params = [];
    $where = "status IN ('new','mine','assigned')";
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_dialogs SET status = 'closed', unread_count = 0, read_at = NOW(), closed_at = NOW(), spam_at = NULL, updated_at = NOW() WHERE {$where}");
    $stmt->execute($params);
    return $stmt->rowCount();
}

function asr_tg_dialog_settings_defaults(): array {
    return [
        'bot_id' => 0,
        'auto_close_incoming' => 0,
        'auto_reply_enabled' => 0,
        'auto_reply_text' => '',
        'auto_reply_attachment_json' => '',
        'auto_reply_attachment' => [],
    ];
}

function asr_tg_dialog_settings_get(PDO $pdo, int $botId = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    $botId = max(0, $botId);
    $defaults = asr_tg_dialog_settings_defaults();
    $targetBotIds = $botId > 0 ? [$botId, 0] : [0];
    foreach ($targetBotIds as $targetBotId) {
        $stmt = $pdo->prepare('SELECT bot_id, auto_close_incoming, auto_reply_enabled, auto_reply_text, auto_reply_attachment_json FROM oca_telegram_bot_dialog_settings WHERE bot_id = ? LIMIT 1');
        $stmt->execute([$targetBotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $attachmentJson = (string)($row['auto_reply_attachment_json'] ?? '');
            $attachment = [];
            if ($attachmentJson !== '') {
                $decodedAttachment = json_decode($attachmentJson, true);
                if (is_array($decodedAttachment)) $attachment = $decodedAttachment;
            }
            return array_merge($defaults, [
                'bot_id' => (int)($row['bot_id'] ?? $targetBotId),
                'auto_close_incoming' => (int)($row['auto_close_incoming'] ?? 0),
                'auto_reply_enabled' => (int)($row['auto_reply_enabled'] ?? 0),
                'auto_reply_text' => (string)($row['auto_reply_text'] ?? ''),
                'auto_reply_attachment_json' => $attachmentJson,
                'auto_reply_attachment' => $attachment,
            ]);
        }
    }
    $defaults['bot_id'] = $botId;
    return $defaults;
}

function asr_tg_dialog_settings_save(PDO $pdo, int $botId, array $data): void {
    asr_tg_repository_ensure_schema($pdo);
    $botId = max(0, $botId);
    $autoClose = !empty($data['auto_close_incoming']) ? 1 : 0;
    $autoReply = !empty($data['auto_reply_enabled']) ? 1 : 0;
    $autoReplyText = trim((string)($data['auto_reply_text'] ?? ''));
    if (mb_strlen($autoReplyText, 'UTF-8') > 2000) {
        $autoReplyText = mb_substr($autoReplyText, 0, 2000, 'UTF-8');
    }
    $current = asr_tg_dialog_settings_get($pdo, $botId);
    $attachmentJson = (string)($current['auto_reply_attachment_json'] ?? '');
    if (!empty($data['auto_reply_attachment_clear'])) {
        $attachmentJson = '';
    }
    if (!empty($data['auto_reply_attachment']) && is_array($data['auto_reply_attachment'])) {
        $allowedAttachment = [];
        foreach (['media_type','file_url','file_path','file_name','mime','size'] as $key) {
            if (isset($data['auto_reply_attachment'][$key])) $allowedAttachment[$key] = $data['auto_reply_attachment'][$key];
        }
        $attachmentJson = $allowedAttachment ? json_encode($allowedAttachment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_dialog_settings (bot_id, auto_close_incoming, auto_reply_enabled, auto_reply_text, auto_reply_attachment_json, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE auto_close_incoming = VALUES(auto_close_incoming), auto_reply_enabled = VALUES(auto_reply_enabled), auto_reply_text = VALUES(auto_reply_text), auto_reply_attachment_json = VALUES(auto_reply_attachment_json), updated_at = NOW()');
    $stmt->execute([$botId, $autoClose, $autoReply, $autoReplyText, $attachmentJson]);
}

function asr_tg_dialog_visible_message_sql(string $alias = 'm'): string {
    return "({$alias}.direction <> 'out' OR {$alias}.payload_json IS NULL OR ({$alias}.payload_json NOT LIKE '%\"broadcast_id\"%' AND {$alias}.payload_json NOT LIKE '%\"flow_id\"%' AND {$alias}.payload_json NOT LIKE '%\"scenario_id\"%'))";
}

function asr_tg_dialogs_counts(PDO $pdo, int $botId = 0, array $filters = []): array {
    asr_tg_repository_ensure_schema($pdo);
    asr_tg_dialogs_backfill($pdo);

    $params = [];
    $where = ['d.subscriber_id IS NOT NULL'];
    if ($botId > 0) {
        $where[] = 'd.bot_id = ?';
        $params[] = $botId;
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.username LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CAST(s.telegram_user_id AS CHAR) LIKE ? OR CAST(s.chat_id AS CHAR) LIKE ? OR lm.message_text LIKE ? OR b.title LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $channelIds = [];
    if (!empty($filters['channel_ids']) && is_array($filters['channel_ids'])) {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $filters['channel_ids']), static fn($v) => $v > 0)));
    }
    if ($channelIds) {
        $where[] = 'd.bot_id IN (' . implode(',', array_fill(0, count($channelIds), '?')) . ')';
        $params = array_merge($params, $channelIds);
    }

    $tagIds = [];
    if (!empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $filters['tag_ids']), static fn($v) => $v > 0)));
    }
    if ($tagIds) {
        $where[] = 'EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags dst WHERE dst.subscriber_id = s.id AND dst.tag_id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . '))';
        $params = array_merge($params, $tagIds);
    }

    $currentUserId = asr_tg_current_user_id();
    // Важно: «Мои» и «Назначенные» - это представления по ответственному, а не отдельная логика прочтения.
    // Индикатор внимания держим до реального исходящего ответа: последнее рабочее сообщение должно быть входящим.
    $currentUserIdSql = (int)$currentUserId;
    $mySql = $currentUserIdSql > 0
        ? "(d.status IN ('mine','assigned') AND d.assigned_user_id = {$currentUserIdSql})"
        : "d.status = 'mine'";
    $assignedSql = $currentUserIdSql > 0
        ? "(d.status IN ('mine','assigned') AND d.assigned_user_id IS NOT NULL AND d.assigned_user_id <> {$currentUserIdSql})"
        : "d.status IN ('mine','assigned') AND d.assigned_user_id IS NOT NULL";
    $needsReplySql = "COALESCE(lm.direction, d.last_direction) = 'in' AND d.status NOT IN ('closed','spam')";

    $countParams = $params;

    $sql = "SELECT
            SUM(CASE WHEN d.status = 'new' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN {$mySql} THEN 1 ELSE 0 END) AS my_count,
            SUM(CASE WHEN {$assignedSql} THEN 1 ELSE 0 END) AS assigned_count,
            SUM(CASE WHEN d.status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
            SUM(CASE WHEN d.status = 'spam' THEN 1 ELSE 0 END) AS spam_count,
            SUM(CASE WHEN d.status = 'new' AND {$needsReplySql} THEN 1 ELSE 0 END) AS new_needs_reply,
            SUM(CASE WHEN {$mySql} AND {$needsReplySql} THEN 1 ELSE 0 END) AS my_needs_reply,
            SUM(CASE WHEN {$assignedSql} AND {$needsReplySql} THEN 1 ELSE 0 END) AS assigned_needs_reply,
            SUM(CASE WHEN {$needsReplySql} THEN 1 ELSE 0 END) AS needs_reply_total,
            SUM(CASE WHEN d.unread_count > 0 AND d.status NOT IN ('closed','spam') THEN 1 ELSE 0 END) AS unread_dialogs,
            COALESCE(SUM(CASE WHEN d.status NOT IN ('closed','spam') THEN d.unread_count ELSE 0 END), 0) AS unread_messages
        FROM oca_telegram_bot_dialogs d
        LEFT JOIN oca_telegram_bot_messages lm ON lm.id = d.last_message_id
        JOIN oca_telegram_bot_subscribers s ON s.id = d.subscriber_id
        JOIN oca_telegram_bots b ON b.id = d.bot_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($countParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    return [
        'new' => (int)($row['new_count'] ?? 0),
        'my' => (int)($row['my_count'] ?? 0),
        'assigned' => (int)($row['assigned_count'] ?? 0),
        'closed' => (int)($row['closed_count'] ?? 0),
        'spam' => (int)($row['spam_count'] ?? 0),
        'unread_dialogs' => (int)($row['unread_dialogs'] ?? 0),
        'unread_messages' => (int)($row['unread_messages'] ?? 0),
        'needs_reply_new' => (int)($row['new_needs_reply'] ?? 0),
        'needs_reply_my' => (int)($row['my_needs_reply'] ?? 0),
        'needs_reply_assigned' => (int)($row['assigned_needs_reply'] ?? 0),
        'needs_reply_total' => (int)($row['needs_reply_total'] ?? 0),
    ];
}

function asr_tg_dialogs_recent(PDO $pdo, int $botId = 0, int $limit = 80, array $filters = []): array {
    asr_tg_repository_ensure_schema($pdo);
    asr_tg_dialogs_backfill($pdo);
    $limit = max(1, min(200, $limit));
    $params = [];
    $where = ['d.subscriber_id IS NOT NULL'];
    if ($botId > 0) {
        $where[] = 'd.bot_id = ?';
        $params[] = $botId;
    }
    $view = (string)($filters['view'] ?? 'new');
    $currentUserId = asr_tg_current_user_id();
    if ($view === 'my') {
        if ($currentUserId > 0) {
            $where[] = "d.status IN ('mine','assigned')";
            $where[] = 'd.assigned_user_id = ?';
            $params[] = $currentUserId;
        } else {
            $where[] = "d.status = 'mine'";
        }
    } elseif ($view === 'assigned') {
        if ($currentUserId > 0) {
            $where[] = "d.status IN ('mine','assigned') AND d.assigned_user_id IS NOT NULL AND d.assigned_user_id <> ?";
            $params[] = $currentUserId;
        } else {
            $where[] = "d.status IN ('mine','assigned') AND d.assigned_user_id IS NOT NULL";
        }
    } elseif (in_array($view, ['new','closed','spam'], true)) {
        $where[] = 'd.status = ?';
        $params[] = $view;
    }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.username LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR CAST(s.telegram_user_id AS CHAR) LIKE ? OR CAST(s.chat_id AS CHAR) LIKE ? OR lm.message_text LIKE ? OR b.title LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($filters['unread_only'])) {
        $where[] = 'd.unread_count > 0';
    }
    $channelIds = [];
    if (!empty($filters['channel_ids']) && is_array($filters['channel_ids'])) {
        $channelIds = array_values(array_unique(array_filter(array_map('intval', $filters['channel_ids']), static fn($v) => $v > 0)));
    }
    if ($channelIds) {
        $where[] = 'd.bot_id IN (' . implode(',', array_fill(0, count($channelIds), '?')) . ')';
        $params = array_merge($params, $channelIds);
    }
    $tagIds = [];
    if (!empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $filters['tag_ids']), static fn($v) => $v > 0)));
    }
    if ($tagIds) {
        $where[] = 'EXISTS (SELECT 1 FROM oca_telegram_bot_subscriber_tags dst WHERE dst.subscriber_id = s.id AND dst.tag_id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . '))';
        $params = array_merge($params, $tagIds);
    }
    $assignedFullNameSql = asr_tg_column_exists($pdo, 'oca_users', 'full_name') ? 'au.full_name' : "''";
    $assignedNameSql = asr_tg_column_exists($pdo, 'oca_users', 'name') ? 'au.name' : "''";
    $assignedFirstNameSql = asr_tg_column_exists($pdo, 'oca_users', 'first_name') ? 'au.first_name' : "''";
    $assignedLastNameSql = asr_tg_column_exists($pdo, 'oca_users', 'last_name') ? 'au.last_name' : "''";
    $assignedUsernameSql = asr_tg_column_exists($pdo, 'oca_users', 'username') ? 'au.username' : "''";
    $assignedTelegramUsernameSql = asr_tg_column_exists($pdo, 'oca_users', 'telegram_username') ? 'au.telegram_username' : "''";
    $assignedEmailSql = asr_tg_column_exists($pdo, 'oca_users', 'email') ? 'au.email' : "''";

    $sql = "SELECT
            d.id AS dialog_id,
            d.status AS dialog_status,
            d.assigned_user_id,
            {$assignedFullNameSql} AS assigned_full_name,
            {$assignedNameSql} AS assigned_name,
            {$assignedFirstNameSql} AS assigned_first_name,
            {$assignedLastNameSql} AS assigned_last_name,
            {$assignedUsernameSql} AS assigned_username,
            {$assignedTelegramUsernameSql} AS assigned_telegram_username,
            {$assignedEmailSql} AS assigned_email,
            d.unread_count,
            d.read_at,
            d.closed_at,
            d.spam_at,
            d.last_message_id,
            d.subscriber_id,
            d.bot_id,
            COALESCE(lm.direction, d.last_direction) AS direction,
            COALESCE(lm.message_type, 'text') AS message_type,
            lm.message_text,
            COALESCE(lm.created_at, d.last_message_at) AS last_message_at,
            s.username,
            s.first_name,
            s.last_name,
            s.telegram_user_id,
            s.chat_id,
            s.status,
            s.last_seen_at,
            b.title AS bot_title,
            b.bot_username,
            b.channel_type,
            b.vk_screen_name
        FROM oca_telegram_bot_dialogs d
        LEFT JOIN oca_telegram_bot_messages lm ON lm.id = d.last_message_id
        JOIN oca_telegram_bot_subscribers s ON s.id = d.subscriber_id
        JOIN oca_telegram_bots b ON b.id = d.bot_id
        LEFT JOIN oca_users au ON au.id = d.assigned_user_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY COALESCE(d.last_message_at, d.updated_at) DESC, d.id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_plain_message_text(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('~<\s*br\s*/?\s*>~iu', "\n", $text) ?? $text;
    $text = preg_replace('~</\s*(p|div|blockquote|pre|li)\s*>~iu', "\n", $text) ?? $text;
    $text = preg_replace('~<\s*(p|div|blockquote|pre|li)[^>]*>~iu', '', $text) ?? $text;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace("~\n{3,}~u", "\n\n", $text) ?? $text;
    return trim($text);
}

function asr_tg_broadcast_context_text(array $row): string {
    $texts = [];
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    $cards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
    if ($cards) {
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $cardText = asr_tg_plain_message_text((string)($card['text'] ?? ''));
            $mediaName = trim((string)($card['media_file_name'] ?? $card['media_url'] ?? ''));
            if ($cardText !== '') $texts[] = $cardText;
            elseif ($mediaName !== '') $texts[] = '[' . trim((string)($card['type'] ?? 'медиа')) . '] ' . $mediaName;
        }
    }
    if (!$texts) {
        $fallback = asr_tg_plain_message_text((string)($row['message_text'] ?? ''));
        if ($fallback !== '') $texts[] = $fallback;
    }
    $text = trim(implode("\n\n", array_slice($texts, 0, 3)));
    if ($text === '') $text = '[рассылка]';
    return mb_substr($text, 0, 1400, 'UTF-8');
}

function asr_tg_subscriber_broadcast_context_rows(PDO $pdo, int $subscriberId, int $botId, string $maxAt, int $limit = 80, string $minAt = ''): array {
    if ($subscriberId <= 0 || $maxAt === '' || !asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcasts')) {
        return [];
    }

    $currentStmt = $pdo->prepare('SELECT id, bot_id, telegram_user_id, chat_id FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1');
    $currentStmt->execute([$subscriberId]);
    $current = $currentStmt ? $currentStmt->fetch(PDO::FETCH_ASSOC) : null;

    // В старых/битых связках диалог может открываться по subscriber_id, который уже есть
    // в сообщениях и очереди рассылок, но не находится через JOIN подписчиков. Для контекста
    // рассылки это не должно быть стоп-фактором: минимально достаточно r.subscriber_id.
    if (!$current) {
        $current = ['id' => $subscriberId, 'bot_id' => $botId, 'telegram_user_id' => 0, 'chat_id' => ''];
    }

    $limit = max(1, min(200, $limit));
    $effectiveBotId = $botId > 0 ? $botId : (int)($current['bot_id'] ?? 0);
    $telegramUserId = (int)($current['telegram_user_id'] ?? 0);
    $chatId = trim((string)($current['chat_id'] ?? ''));

    $selectSql = "SELECT r.id AS recipient_id, r.broadcast_id, r.bot_id, r.subscriber_id, r.sent_at, r.telegram_message_id,
            br.title AS broadcast_title, br.message_text, br.payload_json, br.media_type, br.media_url, br.media_file_name
        FROM oca_telegram_bot_broadcast_recipients r
        JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id
        LEFT JOIN oca_telegram_bot_subscribers rs ON rs.id = r.subscriber_id
        WHERE %s
        ORDER BY r.sent_at ASC, r.id ASC
        LIMIT {$limit}";

    $build = static function(bool $strictBot) use ($subscriberId, $effectiveBotId, $telegramUserId, $chatId, $maxAt, $minAt): array {
        $params = [$maxAt];
        $where = "r.status = 'sent' AND r.sent_at IS NOT NULL AND r.sent_at <= ?";
        if ($minAt !== '') {
            $where .= ' AND r.sent_at > ?';
            $params[] = $minAt;
        }
        if ($strictBot && $effectiveBotId > 0) {
            $where .= ' AND r.bot_id = ?';
            $params[] = $effectiveBotId;
        }

        $identity = ['r.subscriber_id = ?'];
        $params[] = $subscriberId;
        if ($telegramUserId > 0) {
            $identity[] = 'rs.telegram_user_id = ?';
            $params[] = $telegramUserId;
        }
        if ($chatId !== '') {
            $identity[] = 'CAST(r.chat_id AS CHAR) = ?';
            $params[] = $chatId;
        }
        $where .= ' AND (' . implode(' OR ', $identity) . ')';
        return [$where, $params];
    };

    // Сначала ищем строго в текущем канале. Если входящее сообщение привязалось к другой
    // строке этого же человека, делаем мягкий fallback по telegram_user_id/chat_id без смены статуса диалога.
    foreach ([true, false] as $strictBot) {
        [$where, $params] = $build($strictBot);
        $stmt = $pdo->prepare(sprintf($selectSql, $where));
        $stmt->execute($params);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows || !$strictBot) return $rows;
    }
    return [];
}

function asr_tg_message_created_at(PDO $pdo, int $subscriberId, int $messageId, int $botId = 0): string {
    if ($subscriberId <= 0 || $messageId <= 0) return '';
    $params = [$subscriberId, $messageId];
    $where = 'subscriber_id = ? AND id = ?';
    if ($botId > 0) {
        $where .= ' AND bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("SELECT created_at FROM oca_telegram_bot_messages WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    return (string)($stmt ? $stmt->fetchColumn() : '');
}

function asr_tg_inject_broadcast_context_before_incoming(PDO $pdo, array $messages, int $subscriberId, int $botId = 0, string $minAt = ''): array {
    if (!$messages) return [];
    $maxIncomingAt = '';
    foreach ($messages as $message) {
        if ((string)($message['direction'] ?? '') === 'in') {
            $at = (string)($message['created_at'] ?? '');
            if ($at !== '' && ($maxIncomingAt === '' || strcmp($at, $maxIncomingAt) > 0)) $maxIncomingAt = $at;
        }
    }
    if ($maxIncomingAt === '') return $messages;
    $broadcasts = asr_tg_subscriber_broadcast_context_rows($pdo, $subscriberId, $botId, $maxIncomingAt, 120, $minAt);
    if (!$broadcasts) return $messages;

    $shown = [];
    $result = [];
    foreach ($messages as $message) {
        if ((string)($message['direction'] ?? '') === 'in') {
            $messageAt = (string)($message['created_at'] ?? '');
            $best = null;
            foreach ($broadcasts as $broadcast) {
                $broadcastAt = (string)($broadcast['sent_at'] ?? '');
                $broadcastKey = (int)($broadcast['recipient_id'] ?? 0);
                if ($broadcastKey <= 0 || isset($shown[$broadcastKey]) || $broadcastAt === '' || strcmp($broadcastAt, $messageAt) > 0) continue;
                if ($best === null || strcmp($broadcastAt, (string)($best['sent_at'] ?? '')) > 0) $best = $broadcast;
            }
            if ($best !== null) {
                $key = (int)($best['recipient_id'] ?? 0);
                $shown[$key] = true;
                $title = trim((string)($best['broadcast_title'] ?? 'Рассылка'));
                $text = asr_tg_broadcast_context_text($best);
                $result[] = [
                    'id' => 0 - $key,
                    'bot_id' => (int)($best['bot_id'] ?? $botId),
                    'subscriber_id' => $subscriberId,
                    'direction' => 'out',
                    'message_type' => 'broadcast_context',
                    'message_text' => $text,
                    'telegram_message_id' => isset($best['telegram_message_id']) ? (int)$best['telegram_message_id'] : null,
                    'payload_json' => json_encode([
                        'broadcast_context' => true,
                        'broadcast_id' => (int)($best['broadcast_id'] ?? 0),
                        'recipient_id' => $key,
                        'title' => $title,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => (string)($best['sent_at'] ?? $messageAt),
                ];
            }
        }
        $result[] = $message;
    }
    return $result;
}

function asr_tg_dialog_messages(PDO $pdo, int $subscriberId, int $limit = 200, int $botId = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) return [];
    $limit = max(1, min(500, $limit));
    $botId = max(0, $botId);
    $visible = asr_tg_dialog_visible_message_sql('m');
    $params = [$subscriberId];
    $where = "m.subscriber_id = ? AND {$visible}";
    if ($botId > 0) {
        $where .= ' AND m.bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("SELECT m.* FROM oca_telegram_bot_messages m WHERE {$where} ORDER BY m.id ASC LIMIT " . $limit);
    $stmt->execute($params);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return asr_tg_inject_broadcast_context_before_incoming($pdo, $rows, $subscriberId, $botId);
}


function asr_tg_dialog_messages_since(PDO $pdo, int $subscriberId, int $afterMessageId = 0, int $limit = 200, int $botId = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0) return [];
    $limit = max(1, min(500, $limit));
    $afterMessageId = max(0, (int)$afterMessageId);
    $botId = max(0, $botId);
    $visible = asr_tg_dialog_visible_message_sql('m');
    $params = [$subscriberId];
    $where = "m.subscriber_id = ? AND {$visible}";
    if ($botId > 0) {
        $where .= ' AND m.bot_id = ?';
        $params[] = $botId;
    }
    if ($afterMessageId > 0) {
        $where .= ' AND m.id > ?';
        $params[] = $afterMessageId;
    }
    $stmt = $pdo->prepare("SELECT m.* FROM oca_telegram_bot_messages m WHERE {$where} ORDER BY m.id ASC LIMIT " . $limit);
    $stmt->execute($params);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    // Для AJAX-догрузки не отсекаем рассылку временем последнего сообщения: ответ подписчика
    // может прийти после рассылки, а страница в этот момент уже была открыта. Дубли на фронте
    // отсекаются по отрицательному id контекстного блока.
    return asr_tg_inject_broadcast_context_before_incoming($pdo, $rows, $subscriberId, $botId, '');
}

function asr_tg_dialog_allowed_view(string $view): string {
    $view = trim($view);
    return in_array($view, ['new','my','assigned','closed','spam'], true) ? $view : 'new';
}

function asr_tg_dialog_filters_from_source(array $source): array {
    $channels = $source['dialog_channel'] ?? [];
    if (!is_array($channels)) $channels = [$channels];
    $channels = array_values(array_unique(array_filter(array_map('intval', $channels), static fn($v) => $v > 0)));

    $tags = $source['dialog_tag'] ?? [];
    if (!is_array($tags)) $tags = [$tags];
    $tags = array_values(array_unique(array_filter(array_map('intval', $tags), static fn($v) => $v > 0)));

    return [
        'view' => asr_tg_dialog_allowed_view((string)($source['dialog_view'] ?? 'new')),
        'q' => trim((string)($source['dialog_q'] ?? '')),
        'unread_only' => (int)($source['dialog_unread'] ?? 0) === 1,
        'channel_ids' => $channels,
        'tag_ids' => $tags,
    ];
}

function asr_tg_dialog_needs_reply(array $row): bool {
    $status = (string)($row['dialog_status'] ?? $row['status'] ?? 'new');
    $direction = (string)($row['direction'] ?? $row['last_direction'] ?? '');
    return $direction === 'in' && !in_array($status, ['closed','spam'], true);
}

function asr_tg_dialog_display_name(array $row): string {
    $firstName = trim((string)($row['first_name'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $name = trim($firstName . ' ' . $lastName);
    if ($name !== '') return $name;
    $username = trim((string)($row['username'] ?? ''));
    if ($username !== '') return '@' . ltrim($username, '@');
    $telegramUserId = (string)($row['telegram_user_id'] ?? '');
    if ($telegramUserId !== '' && $telegramUserId !== '0') return 'ID ' . $telegramUserId;
    return 'Подписчик #' . (int)($row['subscriber_id'] ?? $row['id'] ?? 0);
}

function asr_tg_dialog_channel_label(array $row): string {
    $type = strtolower((string)($row['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram';
    $title = trim((string)($row['bot_title'] ?? ''));
    if ($title !== '') return $title;
    if ($type === 'vk') {
        $screenName = trim((string)($row['vk_screen_name'] ?? ''));
        return $screenName !== '' ? 'ВК: ' . $screenName : 'ВК';
    }
    $username = trim((string)($row['bot_username'] ?? ''));
    return $username !== '' ? '@' . ltrim($username, '@') : 'Telegram';
}

function asr_tg_dialog_row_payload(array $row): array {
    $status = (string)($row['dialog_status'] ?? $row['status'] ?? 'new');
    $direction = (string)($row['direction'] ?? $row['last_direction'] ?? '');
    $messageText = (string)($row['message_text'] ?? '');
    $messageType = (string)($row['message_type'] ?? 'text');
    $assigneeLabel = asr_tg_dialog_assignee_label($row);
    return [
        'dialog_id' => (int)($row['dialog_id'] ?? $row['id'] ?? 0),
        'subscriber_id' => (int)($row['subscriber_id'] ?? 0),
        'bot_id' => (int)($row['bot_id'] ?? 0),
        'status' => $status,
        'assigned_user_id' => (int)($row['assigned_user_id'] ?? 0),
        'assignee_label' => $assigneeLabel,
        'unread_count' => (int)($row['unread_count'] ?? 0),
        'last_message_id' => (int)($row['last_message_id'] ?? 0),
        'last_message_at' => (string)($row['last_message_at'] ?? ''),
        'last_direction' => $direction,
        'message_type' => $messageType,
        'message_text' => $messageText,
        'preview' => $messageText !== '' ? mb_substr($messageText, 0, 180, 'UTF-8') : '[' . $messageType . ']',
        'needs_reply' => asr_tg_dialog_needs_reply($row),
        'dot' => in_array($status, ['closed','spam'], true) ? 'muted' : (asr_tg_dialog_needs_reply($row) ? 'red' : 'green'),
        'display_name' => asr_tg_dialog_display_name($row),
        'username' => (string)($row['username'] ?? ''),
        'telegram_user_id' => (string)($row['telegram_user_id'] ?? ''),
        'subscriber_status' => (string)($row['status'] ?? ''),
        'channel_type' => strtolower((string)($row['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram',
        'channel_label' => asr_tg_dialog_channel_label($row),
        'bot_title' => (string)($row['bot_title'] ?? ''),
        'bot_username' => (string)($row['bot_username'] ?? ''),
    ];
}

function asr_tg_dialog_message_payload(array $row): array {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) $payload = [];
    $attachment = is_array($payload['attachment'] ?? null) ? $payload['attachment'] : null;
    $messageType = (string)($row['message_type'] ?? 'text');
    $messageText = (string)($row['message_text'] ?? '');
    $isBroadcastContext = $messageType === 'broadcast_context' || !empty($payload['broadcast_context']);
    $isSystem = !$isBroadcastContext && (
        in_array($messageType, ['system','service','event'], true)
        || !empty($payload['system_event'])
        || !empty($payload['dialog_event'])
        || (bool)preg_match('/^(Рассылка|Сценарий|Автоответ)\s*:/u', trim($messageText))
    );
    $cleanText = asr_tg_plain_message_text($messageText);
    return [
        'id' => (int)($row['id'] ?? 0),
        'bot_id' => (int)($row['bot_id'] ?? 0),
        'subscriber_id' => (int)($row['subscriber_id'] ?? 0),
        'direction' => (string)($row['direction'] ?? 'in') === 'out' ? 'out' : 'in',
        'message_type' => $messageType,
        'message_text' => $cleanText !== '' ? $cleanText : $messageText,
        'telegram_message_id' => isset($row['telegram_message_id']) ? (int)$row['telegram_message_id'] : null,
        'created_at' => (string)($row['created_at'] ?? ''),
        'attachment' => $attachment,
        'is_system' => $isSystem,
        'is_broadcast_context' => $isBroadcastContext,
        'broadcast_title' => (string)($payload['title'] ?? 'Рассылка'),
        'broadcast_id' => (int)($payload['broadcast_id'] ?? 0),
    ];
}

function asr_tg_dialog_subscriber_payload(PDO $pdo, array $subscriber): array {
    $subscriberId = (int)($subscriber['id'] ?? 0);
    $botId = (int)($subscriber['bot_id'] ?? 0);
    $tagsMap = $subscriberId > 0 ? asr_tg_subscriber_tags_map($pdo, $botId, [$subscriberId]) : [];
    $memberships = $subscriberId > 0 ? asr_tg_subscriber_channel_memberships($pdo, $subscriber) : [];
    $customFields = function_exists('asr_tg_custom_fields_all') ? asr_tg_custom_fields_all($pdo, 0, true) : [];
    $customValues = function_exists('asr_tg_subscriber_custom_values_get') && $subscriberId > 0 ? asr_tg_subscriber_custom_values_get($pdo, $subscriberId) : [];
    return [
        'id' => $subscriberId,
        'bot_id' => $botId,
        'display_name' => asr_tg_dialog_display_name(['subscriber_id' => $subscriberId] + $subscriber),
        'first_name' => (string)($subscriber['first_name'] ?? ''),
        'last_name' => (string)($subscriber['last_name'] ?? ''),
        'username' => (string)($subscriber['username'] ?? ''),
        'telegram_user_id' => (string)($subscriber['telegram_user_id'] ?? ''),
        'chat_id' => (string)($subscriber['chat_id'] ?? ''),
        'phone' => (string)($subscriber['phone'] ?? ''),
        'email' => (string)($subscriber['email'] ?? ''),
        'status' => (string)($subscriber['status'] ?? ''),
        'admin_note' => (string)($subscriber['admin_note'] ?? ''),
        'first_seen_at' => (string)($subscriber['first_seen_at'] ?? ''),
        'last_seen_at' => (string)($subscriber['last_seen_at'] ?? ''),
        'bot_title' => (string)($subscriber['bot_title'] ?? ''),
        'bot_username' => (string)($subscriber['bot_username'] ?? ''),
        'channel_type' => strtolower((string)($subscriber['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram',
        'utm' => [
            'utm_source' => (string)($subscriber['utm_source'] ?? ''),
            'utm_medium' => (string)($subscriber['utm_medium'] ?? ''),
            'utm_campaign' => (string)($subscriber['utm_campaign'] ?? ''),
            'utm_content' => (string)($subscriber['utm_content'] ?? ''),
            'utm_term' => (string)($subscriber['utm_term'] ?? ''),
        ],
        'tags' => array_values($tagsMap[$subscriberId] ?? []),
        'memberships' => array_map(static function(array $row): array {
            return [
                'subscriber_id' => (int)($row['id'] ?? 0),
                'bot_id' => (int)($row['bot_id'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'bot_title' => (string)($row['bot_title'] ?? ''),
                'bot_username' => (string)($row['bot_username'] ?? ''),
                'channel_type' => strtolower((string)($row['channel_type'] ?? 'telegram')) === 'vk' ? 'vk' : 'telegram',
                'dialog_id' => (int)($row['dialog_id'] ?? 0),
                'dialog_status' => (string)($row['dialog_status'] ?? ''),
                'unread_count' => (int)($row['unread_count'] ?? 0),
                'last_message_at' => (string)($row['last_message_at'] ?? ''),
            ];
        }, $memberships),
        'custom_fields' => array_map(static function(array $field): array {
            return [
                'id' => (int)($field['id'] ?? 0),
                'title' => (string)($field['title'] ?? ''),
                'code' => (string)($field['code'] ?? ''),
                'field_type' => (string)($field['field_type'] ?? 'text'),
            ];
        }, $customFields),
        'custom_values' => $customValues,
    ];
}

function asr_tg_messages_recent(PDO $pdo, int $botId = 0, int $limit = 80): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(300, $limit));
    $sql = 'SELECT m.*, s.username, s.first_name, s.last_name, b.title AS bot_title, b.bot_username
        FROM oca_telegram_bot_messages m
        LEFT JOIN oca_telegram_bot_subscribers s ON s.id = m.subscriber_id
        JOIN oca_telegram_bots b ON b.id = m.bot_id';
    if ($botId > 0) {
        $stmt = $pdo->prepare($sql . ' WHERE m.bot_id = ? ORDER BY m.id DESC LIMIT ' . $limit);
        $stmt->execute([$botId]);
    } else {
        $stmt = $pdo->query($sql . ' ORDER BY m.id DESC LIMIT ' . $limit);
    }
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_active_subscribers(PDO $pdo, int $botId, int $limit = 200): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_subscribers WHERE bot_id = ? AND status = 'active' ORDER BY last_seen_at DESC LIMIT " . $limit);
    $stmt->execute([$botId]);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function asr_tg_subscriber_mark_status(PDO $pdo, int $subscriberId, string $status): void {
    if ($subscriberId <= 0) return;
    $status = mb_substr($status, 0, 30);
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_subscribers SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $subscriberId]);
}



function asr_tg_dialog_minimal_subscriber_from_messages(PDO $pdo, int $subscriberId, int $botId = 0): ?array {
    asr_tg_repository_ensure_schema($pdo);
    if ($subscriberId <= 0 || !asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')) return null;
    $params = [$subscriberId];
    $where = 'm.subscriber_id = ?';
    if ($botId > 0) {
        $where .= ' AND m.bot_id = ?';
        $params[] = $botId;
    }
    $stmt = $pdo->prepare("SELECT m.bot_id, m.subscriber_id, MAX(m.created_at) AS last_message_at, MAX(m.id) AS last_message_id
        FROM oca_telegram_bot_messages m
        WHERE {$where}
        GROUP BY m.bot_id, m.subscriber_id
        ORDER BY last_message_at DESC, last_message_id DESC
        LIMIT 1");
    $stmt->execute($params);
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    if (!$row && $botId > 0) {
        $stmt = $pdo->prepare("SELECT m.bot_id, m.subscriber_id, MAX(m.created_at) AS last_message_at, MAX(m.id) AS last_message_id
            FROM oca_telegram_bot_messages m
            WHERE m.subscriber_id = ?
            GROUP BY m.bot_id, m.subscriber_id
            ORDER BY last_message_at DESC, last_message_id DESC
            LIMIT 1");
        $stmt->execute([$subscriberId]);
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }
    if (!$row) return null;
    return [
        'id' => (int)($row['subscriber_id'] ?? $subscriberId),
        'bot_id' => (int)($row['bot_id'] ?? $botId),
        'username' => '',
        'first_name' => '',
        'last_name' => '',
        'telegram_user_id' => '',
        'chat_id' => '',
        'phone' => '',
        'email' => '',
        'status' => 'active',
        'admin_note' => '',
        'first_seen_at' => '',
        'last_seen_at' => (string)($row['last_message_at'] ?? ''),
        'bot_title' => '',
        'bot_username' => '',
        'channel_type' => 'telegram',
        '_resolved_from_messages_only' => 1,
    ];
}

function asr_tg_dialog_broadcast_context_debug(PDO $pdo, int $subscriberId, int $botId = 0): array {
    $out = [
        'input' => ['subscriber_id' => $subscriberId, 'bot_id' => $botId],
        'subscriber' => null,
        'last_incoming' => null,
        'incoming_candidates' => [],
        'message_subscriber_candidate' => null,
        'matched_recipients' => [],
        'strict_preview' => [],
        'loose_preview' => [],
        'recent_sent_recipients' => [],
    ];
    if ($subscriberId <= 0) return $out;
    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_subscribers')) return $out;

    $subscriber = function_exists('asr_tg_dialog_resolve_subscriber')
        ? asr_tg_dialog_resolve_subscriber($pdo, $subscriberId, $botId)
        : asr_tg_subscriber_find($pdo, $subscriberId, $botId);

    if (!$subscriber) {
        $out['dialog_candidate'] = null;
        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_dialogs')) {
            try {
                $dialogStmt = $pdo->prepare('SELECT id AS dialog_id, bot_id, subscriber_id, status, last_message_id, last_message_at, updated_at FROM oca_telegram_bot_dialogs WHERE id = ? LIMIT 1');
                $dialogStmt->execute([$subscriberId]);
                $out['dialog_candidate'] = $dialogStmt ? ($dialogStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
            } catch (Throwable $e) {
                $out['dialog_candidate_error'] = $e->getMessage();
            }
        }

        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')) {
            try {
                $params = [$subscriberId, $subscriberId];
                $where = "(subscriber_id = ? OR id = ?)";
                if ($botId > 0) {
                    $where .= ' AND bot_id = ?';
                    $params[] = $botId;
                }
                $stmt = $pdo->prepare("SELECT id, bot_id, subscriber_id, direction, message_type, message_text, created_at FROM oca_telegram_bot_messages WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 10");
                $stmt->execute($params);
                $out['incoming_candidates'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $out['incoming_candidates_error'] = $e->getMessage();
            }

            if (!$out['incoming_candidates'] && $botId > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT id, bot_id, subscriber_id, direction, message_type, message_text, created_at FROM oca_telegram_bot_messages WHERE bot_id = ? ORDER BY created_at DESC, id DESC LIMIT 10");
                    $stmt->execute([$botId]);
                    $out['incoming_candidates'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    $out['incoming_candidates_source'] = 'latest_by_bot';
                } catch (Throwable $e) {
                    $out['incoming_candidates_error'] = $e->getMessage();
                }
            }

            foreach (($out['incoming_candidates'] ?? []) as $candidate) {
                $sid = (int)($candidate['subscriber_id'] ?? 0);
                $bid = (int)($candidate['bot_id'] ?? 0);
                if ($sid <= 0) continue;
                $candSubscriber = asr_tg_subscriber_find($pdo, $sid, $bid > 0 ? $bid : 0);
                if ($candSubscriber) {
                    $out['message_subscriber_candidate'] = [
                        'message' => $candidate,
                        'subscriber' => [
                            'id' => (int)($candSubscriber['id'] ?? 0),
                            'bot_id' => (int)($candSubscriber['bot_id'] ?? 0),
                            'telegram_user_id' => (string)($candSubscriber['telegram_user_id'] ?? ''),
                            'chat_id' => (string)($candSubscriber['chat_id'] ?? ''),
                            'username' => (string)($candSubscriber['username'] ?? ''),
                            'first_name' => (string)($candSubscriber['first_name'] ?? ''),
                            'last_name' => (string)($candSubscriber['last_name'] ?? ''),
                        ],
                    ];
                    break;
                }
            }
        }

        if (asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients')) {
            try {
                $recent = $pdo->query("SELECT id AS recipient_id, broadcast_id, bot_id, subscriber_id, chat_id, status, sent_at, created_at, updated_at, last_error FROM oca_telegram_bot_broadcast_recipients WHERE status = 'sent' ORDER BY COALESCE(sent_at, updated_at, created_at) DESC, id DESC LIMIT 10");
                $out['recent_sent_recipients'] = $recent ? $recent->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $out['recent_sent_error'] = $e->getMessage();
            }
        }
        return $out;
    }
    $out['subscriber'] = [
        'id' => (int)($subscriber['id'] ?? 0),
        'bot_id' => (int)($subscriber['bot_id'] ?? 0),
        'telegram_user_id' => (string)($subscriber['telegram_user_id'] ?? ''),
        'chat_id' => (string)($subscriber['chat_id'] ?? ''),
        'username' => (string)($subscriber['username'] ?? ''),
        'first_name' => (string)($subscriber['first_name'] ?? ''),
        'last_name' => (string)($subscriber['last_name'] ?? ''),
        'resolved_from_dialog_id' => (int)($subscriber['_resolved_from_dialog_id'] ?? 0),
        'resolved_from_last_incoming' => (int)($subscriber['_resolved_from_last_incoming'] ?? 0),
    ];

    $realSubscriberId = (int)($subscriber['id'] ?? $subscriberId);
    $effectiveBotId = (int)($subscriber['bot_id'] ?? $botId);

    $params = [$realSubscriberId];
    $where = "subscriber_id = ? AND direction = 'in'";
    if ($effectiveBotId > 0) { $where .= ' AND bot_id = ?'; $params[] = $effectiveBotId; }
    if (asr_tg_table_exists($pdo, 'oca_telegram_bot_messages')) {
        $stmt = $pdo->prepare("SELECT id, bot_id, subscriber_id, direction, message_type, message_text, created_at FROM oca_telegram_bot_messages WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute($params);
        $out['last_incoming'] = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcast_recipients') || !asr_tg_table_exists($pdo, 'oca_telegram_bot_broadcasts')) return $out;

    $recentStmt = $pdo->query("SELECT id AS recipient_id, broadcast_id, bot_id, subscriber_id, chat_id, status, sent_at, created_at, updated_at, last_error FROM oca_telegram_bot_broadcast_recipients WHERE status = 'sent' ORDER BY COALESCE(sent_at, updated_at, created_at) DESC, id DESC LIMIT 10");
    $out['recent_sent_recipients'] = $recentStmt ? $recentStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
    $chatId = trim((string)($subscriber['chat_id'] ?? ''));

    $clauses = ['r.subscriber_id = ?'];
    $qParams = [$realSubscriberId];
    if ($telegramUserId > 0) { $clauses[] = 'rs.telegram_user_id = ?'; $qParams[] = $telegramUserId; }
    if ($chatId !== '') { $clauses[] = 'CAST(r.chat_id AS CHAR) = ?'; $qParams[] = $chatId; }
    $botSql = '';
    if ($effectiveBotId > 0) { $botSql = ' AND r.bot_id = ?'; $qParams[] = $effectiveBotId; }

    $stmt = $pdo->prepare("SELECT r.id AS recipient_id, r.broadcast_id, r.bot_id, r.subscriber_id, r.chat_id, r.status, r.sent_at, r.created_at, r.updated_at, r.last_error, br.title AS broadcast_title, rs.telegram_user_id AS recipient_telegram_user_id
        FROM oca_telegram_bot_broadcast_recipients r
        JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id
        LEFT JOIN oca_telegram_bot_subscribers rs ON rs.id = r.subscriber_id
        WHERE (" . implode(' OR ', $clauses) . "){$botSql}
        ORDER BY COALESCE(r.sent_at, r.updated_at, r.created_at) DESC, r.id DESC
        LIMIT 10");
    $stmt->execute($qParams);
    $out['matched_recipients'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $maxAt = (string)($out['last_incoming']['created_at'] ?? '');
    if ($maxAt !== '') {
        if (function_exists('asr_tg_subscriber_broadcast_context_rows')) {
            $out['strict_preview'] = asr_tg_subscriber_broadcast_context_rows($pdo, $realSubscriberId, $effectiveBotId, $maxAt, 10, '');
            $out['loose_preview'] = asr_tg_subscriber_broadcast_context_rows($pdo, $realSubscriberId, 0, $maxAt, 10, '');
        }
    }
    return $out;
}

function asr_tg_broadcast_add(PDO $pdo, int $botId, string $title, string $messageText, int $createdBy = 0, array $options = []): int {
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_broadcasts (bot_id, created_by, title, message_text, parse_mode, media_type, media_url, media_file_path, media_file_name, payload_json, segment_json, disable_web_page_preview, status, scheduled_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $botId,
        $createdBy ?: null,
        $title,
        $messageText,
        $options['parse_mode'] ?? 'HTML',
        $options['media_type'] ?? null,
        $options['media_url'] ?? null,
        $options['media_file_path'] ?? null,
        $options['media_file_name'] ?? null,
        $options['payload_json'] ?? null,
        $options['segment_json'] ?? null,
        !empty($options['disable_web_page_preview']) ? 1 : 0,
        $options['status'] ?? 'draft',
        $options['scheduled_at'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function asr_tg_broadcast_find(PDO $pdo, int $broadcastId): ?array {
    if ($broadcastId <= 0) return null;
    $stmt = $pdo->prepare('SELECT br.*, b.title AS bot_title, b.bot_username, b.bot_token_encrypted FROM oca_telegram_bot_broadcasts br JOIN oca_telegram_bots b ON b.id = br.bot_id WHERE br.id = ? LIMIT 1');
    $stmt->execute([$broadcastId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_tg_broadcasts_find_by_ids(PDO $pdo, array $broadcastIds): array {
    asr_tg_repository_ensure_schema($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $broadcastIds), static fn($id) => $id > 0)));
    if (!$ids) return [];
    $ids = array_slice($ids, 0, 50);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT br.*, b.title AS bot_title, b.bot_username, b.bot_token_encrypted FROM oca_telegram_bot_broadcasts br JOIN oca_telegram_bots b ON b.id = br.bot_id WHERE br.id IN (' . $placeholders . ') ORDER BY br.id DESC');
    $stmt->execute($ids);
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}


function asr_tg_broadcast_scheduled_group_ids(PDO $pdo, int $broadcastId): array {
    asr_tg_repository_ensure_schema($pdo);
    if ($broadcastId <= 0) return [];
    $base = asr_tg_broadcast_find($pdo, $broadcastId);
    if (!$base) return [];
    if ((string)($base['status'] ?? '') !== 'scheduled') return [];

    $createdAt = trim((string)($base['created_at'] ?? ''));
    $scheduledAt = trim((string)($base['scheduled_at'] ?? ''));
    if ($createdAt === '' || $scheduledAt === '') return [$broadcastId];

    // В мультиканальной рассылке создаётся по одной записи на канал.
    // У них разные title из-за суффикса канала, но одинаковые payload/segment/message/scheduled_at
    // и почти одинаковый created_at. По этим признакам отменяем всю ещё не стартовавшую группу.
    $stmt = $pdo->prepare("SELECT id
        FROM oca_telegram_bot_broadcasts
        WHERE status = 'scheduled'
          AND scheduled_at = ?
          AND COALESCE(created_by, 0) = COALESCE(?, 0)
          AND COALESCE(message_text, '') = COALESCE(?, '')
          AND COALESCE(payload_json, '') = COALESCE(?, '')
          AND COALESCE(segment_json, '') = COALESCE(?, '')
          AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) <= 10
        ORDER BY id ASC");
    $stmt->execute([
        $scheduledAt,
        $base['created_by'] ?? null,
        (string)($base['message_text'] ?? ''),
        (string)($base['payload_json'] ?? ''),
        (string)($base['segment_json'] ?? ''),
        $createdAt,
    ]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn($id) => $id > 0)));
    return $ids ?: [$broadcastId];
}

function asr_tg_broadcast_update_result(PDO $pdo, int $broadcastId, array $data): void {
    $allowed = ['status','scheduled_at','total_recipients','sent_count','failed_count','last_error','started_at','queued_at','finished_at','cancelled_at'];
    $sets = [];
    $values = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) {
            $sets[] = "`{$key}` = ?";
            $values[] = $data[$key];
        }
    }
    if (!$sets) return;
    $sets[] = '`updated_at` = NOW()';
    $values[] = $broadcastId;
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_broadcasts SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}

function asr_tg_segment_known_sql(string $expr, bool $unknown = false): string {
    $sql = "({$expr} IS NOT NULL AND TRIM(CAST({$expr} AS CHAR)) <> '')";
    return $unknown ? "NOT {$sql}" : $sql;
}

function asr_tg_segment_compare_sql(string $expr, string $op, string $value, string $value2, array &$params, string $fieldType = 'text'): string {
    if (in_array($op, ['known','has_value'], true)) return asr_tg_segment_known_sql($expr, false);
    if (in_array($op, ['unknown','empty'], true)) return asr_tg_segment_known_sql($expr, true);
    if ($op === 'contains') { $params[] = '%' . $value . '%'; return "CAST({$expr} AS CHAR) LIKE ?"; }
    if ($op === 'not_contains') { $params[] = '%' . $value . '%'; return "(CAST({$expr} AS CHAR) NOT LIKE ? OR {$expr} IS NULL)"; }
    if ($op === 'between') { $params[] = $value; $params[] = $value2; return "{$expr} BETWEEN ? AND ?"; }
    if ($op === 'after_date') { $params[] = $value; return "{$expr} >= ?"; }
    if ($op === 'before_date') { $params[] = $value; return "{$expr} <= ?"; }
    if ($op === 'greater') { $params[] = $value; return "{$expr} > ?"; }
    if ($op === 'less') { $params[] = $value; return "{$expr} < ?"; }
    if ($op === 'not_equals') { $params[] = $value; return "({$expr} <> ? OR {$expr} IS NULL)"; }
    $params[] = $value;
    return "{$expr} = ?";
}

function asr_tg_segment_condition_sql(array $condition, array &$params): string {
    $field = trim((string)($condition['field'] ?? ''));
    $op = trim((string)($condition['op'] ?? ''));
    $value = trim((string)($condition['value'] ?? ''));
    $value2 = trim((string)($condition['value2'] ?? ''));

    $legacyMap = [
        'name' => 'system.full_name',
        'username' => 'subscriber.username',
        'language' => 'subscriber.language_code',
        'status' => 'subscriber.status',
        'subscribed_at' => 'subscriber.first_seen_at',
        'last_contact_at' => 'subscriber.last_seen_at',
    ];
    if (isset($legacyMap[$field])) $field = $legacyMap[$field];
    if ($op === 'has_value') $op = 'known';
    if ($op === 'empty') $op = 'unknown';

    if ($field === 'system.full_name') {
        return asr_tg_segment_compare_sql("TRIM(CONCAT_WS(' ', s.first_name, s.last_name))", $op, $value, $value2, $params, 'text');
    }

    if (preg_match('/^subscriber\.([A-Za-z0-9_]+)$/', $field, $m)) {
        $column = $m[1];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return '1=1';
        $expr = 's.`' . $column . '`';
        if ($op === 'more_days') {
            $days = max(0, min(3650, (int)$value));
            return "{$expr} < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
        }
        if ($op === 'less_days') {
            $days = max(0, min(3650, (int)$value));
            return "{$expr} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
        }
        return asr_tg_segment_compare_sql($expr, $op, $value, $value2, $params, 'text');
    }

    if ($field === 'bot.title' || $field === 'bot.username' || $field === 'bot.channel_type') {
        $column = $field === 'bot.username' ? 'bot_username' : ($field === 'bot.channel_type' ? 'channel_type' : 'title');
        $expr = '(SELECT b.`' . $column . '` FROM oca_telegram_bots b WHERE b.id = s.bot_id LIMIT 1)';
        return asr_tg_segment_compare_sql($expr, $op, $value, $value2, $params, 'text');
    }

    if ($field === 'tag.id') {
        $tagId = max(0, (int)$value);
        if ($tagId <= 0) return '1=1';
        $tagSql = 'SELECT 1 FROM oca_telegram_bot_subscriber_tags st JOIN oca_telegram_bot_subscribers sx ON sx.id = st.subscriber_id WHERE st.tag_id = ? AND (sx.id = s.id OR (s.telegram_user_id > 0 AND sx.telegram_user_id = s.telegram_user_id))';
        $params[] = $tagId;
        return $op === 'not_has_tag' ? 'NOT EXISTS (' . $tagSql . ')' : 'EXISTS (' . $tagSql . ')';
    }

    if (preg_match('/^custom\.([0-9]+)$/', $field, $m)) {
        $fieldId = (int)$m[1];
        if ($fieldId <= 0) return '1=1';
        $base = 'SELECT 1 FROM oca_telegram_bot_subscriber_custom_values cfv JOIN oca_telegram_bot_subscribers sx ON sx.id = cfv.subscriber_id WHERE cfv.field_id = ? AND (sx.id = s.id OR (s.telegram_user_id > 0 AND sx.telegram_user_id = s.telegram_user_id))';
        if (in_array($op, ['known','has_value'], true)) {
            $params[] = $fieldId;
            return 'EXISTS (' . $base . " AND (TRIM(COALESCE(cfv.value_text, '')) <> '' OR cfv.value_number IS NOT NULL OR cfv.value_date IS NOT NULL OR cfv.value_datetime IS NOT NULL))";
        }
        if (in_array($op, ['unknown','empty'], true)) {
            $params[] = $fieldId;
            return 'NOT EXISTS (' . $base . " AND (TRIM(COALESCE(cfv.value_text, '')) <> '' OR cfv.value_number IS NOT NULL OR cfv.value_date IS NOT NULL OR cfv.value_datetime IS NOT NULL))";
        }
        if (in_array($op, ['greater','less'], true)) {
            $params[] = $fieldId; $params[] = $value;
            return 'EXISTS (' . $base . ' AND cfv.value_number ' . ($op === 'greater' ? '>' : '<') . ' ?)';
        }
        if (in_array($op, ['after_date','before_date'], true)) {
            $params[] = $fieldId; $params[] = $value;
            return 'EXISTS (' . $base . ' AND COALESCE(cfv.value_datetime, cfv.value_date) ' . ($op === 'after_date' ? '>=' : '<=') . ' ?)';
        }
        if ($op === 'between') {
            $params[] = $fieldId; $params[] = $value; $params[] = $value2;
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value2)) {
                return 'EXISTS (' . $base . ' AND COALESCE(cfv.value_datetime, cfv.value_date) BETWEEN ? AND ?)';
            }
            return 'EXISTS (' . $base . ' AND cfv.value_number BETWEEN ? AND ?)';
        }
        $expr = "COALESCE(cfv.value_text, CAST(cfv.value_number AS CHAR), CAST(cfv.value_date AS CHAR), CAST(cfv.value_datetime AS CHAR), '')";
        if ($op === 'contains') { $params[] = $fieldId; $params[] = '%' . $value . '%'; return 'EXISTS (' . $base . " AND {$expr} LIKE ?)"; }
        if ($op === 'not_contains') { $params[] = $fieldId; $params[] = '%' . $value . '%'; return 'NOT EXISTS (' . $base . " AND {$expr} LIKE ?)"; }
        if ($op === 'not_equals') { $params[] = $fieldId; $params[] = $value; return 'NOT EXISTS (' . $base . " AND {$expr} = ?)"; }
        $params[] = $fieldId; $params[] = $value;
        return 'EXISTS (' . $base . " AND {$expr} = ?)";
    }

    return '1=1';
}

function asr_tg_segment_apply_conditions_sql(array $segment, array &$params): string {
    $conditions = is_array($segment['conditions'] ?? null) ? $segment['conditions'] : [];
    $combinedSql = '';
    $index = 0;
    foreach ($conditions as $condition) {
        if (!is_array($condition)) continue;
        $sql = asr_tg_segment_condition_sql($condition, $params);
        if ($sql === '1=1') continue;
        if ($combinedSql === '') {
            $combinedSql = '(' . $sql . ')';
        } else {
            $joiner = strtolower((string)($condition['joiner'] ?? 'and')) === 'or' ? ' OR ' : ' AND ';
            if (($segment['match'] ?? '') === 'any') $joiner = ' OR ';
            elseif (($segment['match'] ?? '') === 'all') $joiner = ' AND ';
            $combinedSql = '(' . $combinedSql . $joiner . '(' . $sql . '))';
        }
        $index++;
    }
    return $combinedSql;
}

function asr_tg_segment_where_sql(int $botId, array $segment, array &$params): string {
    $params = [$botId];
    $where = "s.bot_id = ? AND s.status = 'active'";
    $conditionsSql = asr_tg_segment_apply_conditions_sql($segment, $params);
    if ($conditionsSql !== '') $where .= ' AND (' . $conditionsSql . ')';
    return $where;
}

function asr_tg_segment_where_sql_for_bots(array $botIds, array $segment, array &$params): string {
    $ids = [];
    foreach ($botIds as $botId) {
        $id = (int)$botId;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    if (!$ids) {
        $params = [];
        return '1=0';
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $where = "s.bot_id IN ({$placeholders}) AND s.status = 'active'";
    $conditionsSql = asr_tg_segment_apply_conditions_sql($segment, $params);
    if ($conditionsSql !== '') $where .= ' AND (' . $conditionsSql . ')';
    return $where;
}

function asr_tg_segment_count_for_bots(PDO $pdo, array $botIds, array $segment = [], bool $allowDuplicates = false): int {
    asr_tg_repository_ensure_schema($pdo);
    $params = [];
    $where = asr_tg_segment_where_sql_for_bots($botIds, $segment, $params);
    $expr = $allowDuplicates ? 'COUNT(*)' : "COUNT(DISTINCT COALESCE(NULLIF(s.telegram_user_id, 0), CONCAT('s:', s.id)))";
    $stmt = $pdo->prepare("SELECT {$expr} FROM oca_telegram_bot_subscribers s WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function asr_tg_broadcast_queue_recipients_for_group(PDO $pdo, array $broadcastIdsByBot, array $botIds, array $segment = [], bool $allowDuplicates = false, ?string $scheduledAt = null): array {
    $orderedBotIds = [];
    foreach ($botIds as $botId) {
        $id = (int)$botId;
        if ($id > 0 && isset($broadcastIdsByBot[$id]) && !in_array($id, $orderedBotIds, true)) $orderedBotIds[] = $id;
    }
    if (!$orderedBotIds) return ['total' => 0, 'counts_by_bot' => []];

    $params = [];
    $where = asr_tg_segment_where_sql_for_bots($orderedBotIds, $segment, $params);
    $caseParts = [];
    foreach ($orderedBotIds as $index => $botId) {
        $caseParts[] = 'WHEN ' . (int)$botId . ' THEN ' . (int)$index;
    }
    $orderExpr = 'CASE s.bot_id ' . implode(' ', $caseParts) . ' ELSE 999 END';

    $stmt = $pdo->prepare("SELECT s.id AS subscriber_id, s.bot_id, s.chat_id, s.telegram_user_id
        FROM oca_telegram_bot_subscribers s
        WHERE {$where}
        ORDER BY {$orderExpr}, s.id ASC");
    $stmt->execute($params);

    $recipientScheduledAt = $scheduledAt ?: date('Y-m-d H:i:s');
    $insert = $pdo->prepare("INSERT IGNORE INTO oca_telegram_bot_broadcast_recipients (broadcast_id, bot_id, subscriber_id, chat_id, status, scheduled_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
    $seenPeople = [];
    $counts = [];
    $total = 0;
    foreach ($orderedBotIds as $botId) $counts[$botId] = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $botId = (int)($row['bot_id'] ?? 0);
        $broadcastId = (int)($broadcastIdsByBot[$botId] ?? 0);
        if ($botId <= 0 || $broadcastId <= 0) continue;
        if (!$allowDuplicates) {
            $telegramUserId = (string)($row['telegram_user_id'] ?? '');
            $personKey = ($telegramUserId !== '' && $telegramUserId !== '0') ? ('tg:' . $telegramUserId) : ('s:' . (int)$row['subscriber_id']);
            if (isset($seenPeople[$personKey])) continue;
            $seenPeople[$personKey] = true;
        }
        $insert->execute([$broadcastId, $botId, (int)$row['subscriber_id'], (string)$row['chat_id'], $recipientScheduledAt]);
        if ($insert->rowCount() > 0) {
            $counts[$botId] = (int)($counts[$botId] ?? 0) + 1;
            $total++;
        }
    }

    return ['total' => $total, 'counts_by_bot' => $counts];
}

function asr_tg_segment_count(PDO $pdo, int $botId, array $segment = []): int {
    asr_tg_repository_ensure_schema($pdo);
    if ($botId <= 0) return 0;
    $params = [];
    $where = asr_tg_segment_where_sql($botId, $segment, $params);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_subscribers s WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function asr_tg_broadcast_queue_recipients(PDO $pdo, int $broadcastId, int $botId, array $segment = [], ?string $scheduledAt = null): int {
    $params = [];
    $where = asr_tg_segment_where_sql($botId, $segment, $params);
    $recipientScheduledAt = $scheduledAt ?: date('Y-m-d H:i:s');
    $sql = "INSERT IGNORE INTO oca_telegram_bot_broadcast_recipients (broadcast_id, bot_id, subscriber_id, chat_id, status, scheduled_at, created_at, updated_at)
        SELECT ?, ?, s.id, s.chat_id, 'pending', ?, NOW(), NOW()
        FROM oca_telegram_bot_subscribers s
        WHERE {$where}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$broadcastId, $botId, $recipientScheduledAt], $params));
    return (int)$stmt->rowCount();
}


function asr_tg_broadcast_reset_stale_processing(PDO $pdo, int $broadcastId = 0, int $minutes = 10): int {
    $minutes = max(1, min(120, $minutes));
    $where = "status = 'processing' AND (processing_at IS NULL OR processing_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE))";
    $params = [];
    if ($broadcastId > 0) {
        $where .= ' AND broadcast_id = ?';
        $params[] = $broadcastId;
    }
    $nextAttemptSql = asr_tg_column_exists($pdo, 'oca_telegram_bot_broadcast_recipients', 'next_attempt_at') ? ', next_attempt_at = NOW()' : '';
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_broadcast_recipients SET status = 'retry', processing_at = NULL{$nextAttemptSql}, updated_at = NOW() WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

function asr_tg_broadcast_recalc(PDO $pdo, int $broadcastId): array {
    $stmt = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(status = 'sent') AS sent,
        SUM(status = 'failed') AS failed,
        SUM(status = 'pending') AS pending,
        SUM(status = 'processing') AS processing,
        SUM(status = 'retry') AS retry
        FROM oca_telegram_bot_broadcast_recipients WHERE broadcast_id = ?");
    $stmt->execute([$broadcastId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'sent'=>0,'failed'=>0,'pending'=>0,'processing'=>0,'retry'=>0];
    $total = (int)($stats['total'] ?? 0);
    $sent = (int)($stats['sent'] ?? 0);
    $failed = (int)($stats['failed'] ?? 0);
    $pending = (int)($stats['pending'] ?? 0);
    $processing = (int)($stats['processing'] ?? 0);
    $retry = (int)($stats['retry'] ?? 0);
    $currentStatus = '';
    $currentStmt = $pdo->prepare('SELECT status FROM oca_telegram_bot_broadcasts WHERE id = ? LIMIT 1');
    $currentStmt->execute([$broadcastId]);
    $currentStatus = (string)($currentStmt->fetchColumn() ?: '');
    if ($currentStatus === 'cancelled') {
        return ['total'=>$total,'sent'=>$sent,'failed'=>$failed,'pending'=>$pending,'processing'=>$processing,'retry'=>$retry,'status'=>'cancelled'];
    }

    $status = ($pending + $processing + $retry) > 0 ? 'processing' : ($failed > 0 ? 'finished_with_errors' : 'finished');
    if ($total === 0) {
        // Нулевая рассылка по отдельному каналу в мультиканальной группе - не ошибка отправки.
        // Сохраняем status=skipped, чтобы отчёт не красил всю группу красным.
        $status = ($currentStatus === 'skipped') ? 'skipped' : 'failed';
    }

    $lastError = null;
    if ($failed > 0) {
        $errStmt = $pdo->prepare("SELECT last_error FROM oca_telegram_bot_broadcast_recipients WHERE broadcast_id = ? AND status = 'failed' AND last_error IS NOT NULL AND last_error <> '' ORDER BY updated_at DESC, id DESC LIMIT 1");
        $errStmt->execute([$broadcastId]);
        $lastError = $errStmt->fetchColumn();
        if ($lastError !== false) $lastError = mb_substr((string)$lastError, 0, 1000, 'UTF-8');
        else $lastError = null;
    }

    asr_tg_broadcast_update_result($pdo, $broadcastId, [
        'status' => $status,
        'total_recipients' => $total,
        'sent_count' => $sent,
        'failed_count' => $failed,
        'last_error' => $lastError,
        'finished_at' => ($pending + $processing + $retry) > 0 ? null : date('Y-m-d H:i:s'),
    ]);
    return ['total'=>$total,'sent'=>$sent,'failed'=>$failed,'pending'=>$pending,'processing'=>$processing,'retry'=>$retry,'status'=>$status];
}

function asr_tg_broadcast_app_timezone(): DateTimeZone {
    $name = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
    try {
        return new DateTimeZone($name);
    } catch (Throwable $e) {
        return new DateTimeZone('Asia/Almaty');
    }
}

function asr_tg_broadcast_now_sql(): string {
    return (new DateTimeImmutable('now', asr_tg_broadcast_app_timezone()))->format('Y-m-d H:i:s');
}

function asr_tg_broadcast_activate_due_scheduled(PDO $pdo, int $limit = 200): int {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));
    $now = asr_tg_broadcast_now_sql();
    // Не используем SQL NOW(): у хостинга/БД часовой пояс может отличаться от времени,
    // которое оператор выбирает в админке. Сравниваем с единым временем приложения.
    $idsStmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_broadcasts WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at ASC, id ASC LIMIT {$limit}");
    $idsStmt->execute([$now]);
    $ids = array_values(array_filter(array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn($id) => $id > 0));
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$now, $now], $ids);
    $stmt = $pdo->prepare("UPDATE oca_telegram_bot_broadcasts SET status = 'queued', queued_at = ?, updated_at = ? WHERE id IN ({$placeholders}) AND status = 'scheduled'");
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

function asr_tg_broadcast_due_scheduled_count(PDO $pdo): int {
    asr_tg_repository_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_broadcasts WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?");
    $stmt->execute([asr_tg_broadcast_now_sql()]);
    return (int)$stmt->fetchColumn();
}

function asr_tg_broadcast_recipients_next(PDO $pdo, int $limit = 30, int $broadcastId = 0): array {
    $limit = max(1, min(200, $limit));
    $now = asr_tg_broadcast_now_sql();
    $hasNextAttempt = asr_tg_column_exists($pdo, 'oca_telegram_bot_broadcast_recipients', 'next_attempt_at');
    if ($hasNextAttempt) {
        $where = "((r.status = 'pending' AND r.scheduled_at <= ?) OR (r.status = 'retry' AND COALESCE(r.next_attempt_at, r.scheduled_at) <= ?)) AND br.status IN ('queued','processing')";
        $params = [$now, $now];
        $orderExpr = 'COALESCE(r.next_attempt_at, r.scheduled_at)';
    } else {
        $where = "r.status = 'pending' AND r.scheduled_at <= ? AND br.status IN ('queued','processing')";
        $params = [$now];
        $orderExpr = 'r.scheduled_at';
    }
    if ($broadcastId > 0) { $where .= ' AND r.broadcast_id = ?'; $params[] = $broadcastId; }
    $stmt = $pdo->prepare("SELECT r.*, br.message_text, br.parse_mode, br.media_type, br.media_url, br.media_file_path, br.media_file_name, br.payload_json, br.disable_web_page_preview, br.bot_id, r.id AS recipient_id, br.title AS broadcast_title, b.bot_token_encrypted
        FROM oca_telegram_bot_broadcast_recipients r
        JOIN oca_telegram_bot_broadcasts br ON br.id = r.broadcast_id
        JOIN oca_telegram_bots b ON b.id = r.bot_id
        WHERE {$where}
        ORDER BY {$orderExpr} ASC, r.id ASC
        LIMIT {$limit}");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_broadcast_recipient_update(PDO $pdo, int $recipientId, array $data): void {
    $allowed = ['status','attempts','last_error','telegram_message_id','processing_at','sent_at','scheduled_at','next_attempt_at'];
    $sets = [];
    $values = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) { $sets[] = "`{$key}` = ?"; $values[] = $data[$key]; }
    }
    if (!$sets) return;
    $sets[] = '`updated_at` = NOW()';
    $values[] = $recipientId;
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_broadcast_recipients SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}

function asr_tg_broadcast_recent_recipients(PDO $pdo, int $broadcastId, int $limit = 40): array {
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("SELECT r.*, s.username, s.first_name, s.last_name
        FROM oca_telegram_bot_broadcast_recipients r
        LEFT JOIN oca_telegram_bot_subscribers s ON s.id = r.subscriber_id
        WHERE r.broadcast_id = ?
        ORDER BY r.id DESC
        LIMIT {$limit}");
    $stmt->execute([$broadcastId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function asr_tg_broadcast_error_summary(PDO $pdo, int $broadcastId, int $limit = 5): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(20, $limit));
    if ($broadcastId <= 0) return [];

    $stmt = $pdo->prepare("SELECT r.id, r.bot_id, r.subscriber_id, r.chat_id, r.status, r.attempts, r.last_error, r.updated_at, s.username, s.first_name, s.last_name, s.telegram_user_id
        FROM oca_telegram_bot_broadcast_recipients r
        LEFT JOIN oca_telegram_bot_subscribers s ON s.id = r.subscriber_id
        WHERE r.broadcast_id = ? AND r.status = 'failed'
        ORDER BY r.updated_at DESC, r.id DESC
        LIMIT {$limit}");
    $stmt->execute([$broadcastId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function asr_tg_broadcast_report_ids(array $broadcastIds): array {
    return array_values(array_unique(array_filter(array_map('intval', $broadcastIds), static fn($id) => $id > 0)));
}

function asr_tg_broadcast_recipient_status_counts(PDO $pdo, array $broadcastIds): array {
    asr_tg_repository_ensure_schema($pdo);
    $ids = asr_tg_broadcast_report_ids($broadcastIds);
    $result = ['pending'=>0, 'processing'=>0, 'sent'=>0, 'failed'=>0, 'total'=>0, 'done'=>0, 'left'=>0];
    if (!$ids) return $result;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM oca_telegram_bot_broadcast_recipients WHERE broadcast_id IN ({$placeholders}) GROUP BY status");
    $stmt->execute($ids);
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $status = (string)($row['status'] ?? '');
        $cnt = (int)($row['cnt'] ?? 0);
        if ($status === '') continue;
        $result[$status] = ($result[$status] ?? 0) + $cnt;
        $result['total'] += $cnt;
    }
    $result['pending'] = (int)($result['pending'] ?? 0);
    $result['processing'] = (int)($result['processing'] ?? 0);
    $result['sent'] = (int)($result['sent'] ?? 0);
    $result['failed'] = (int)($result['failed'] ?? 0);
    $result['done'] = $result['sent'] + $result['failed'];
    $result['left'] = max(0, $result['total'] - $result['done']);
    return $result;
}

function asr_tg_broadcast_report_times(PDO $pdo, array $broadcastIds): array {
    asr_tg_repository_ensure_schema($pdo);
    $ids = asr_tg_broadcast_report_ids($broadcastIds);
    $empty = ['created_at'=>'', 'queued_at'=>'', 'started_at'=>'', 'finished_at'=>'', 'first_sent_at'=>'', 'last_sent_at'=>'', 'updated_at'=>''];
    if (!$ids) return $empty;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT MIN(created_at) AS created_at, MIN(queued_at) AS queued_at, MIN(started_at) AS started_at, MAX(finished_at) AS finished_at, MAX(updated_at) AS updated_at FROM oca_telegram_bot_broadcasts WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $times = array_merge($empty, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    $sentStmt = $pdo->prepare("SELECT MIN(sent_at) AS first_sent_at, MAX(sent_at) AS last_sent_at FROM oca_telegram_bot_broadcast_recipients WHERE broadcast_id IN ({$placeholders}) AND status = 'sent'");
    $sentStmt->execute($ids);
    $sentTimes = $sentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($sentTimes as $key => $value) $times[$key] = $value;
    foreach ($times as $key => $value) $times[$key] = $value ? (string)$value : '';
    return $times;
}

function asr_tg_broadcast_error_summary_for_ids(PDO $pdo, array $broadcastIds, int $limit = 10): array {
    asr_tg_repository_ensure_schema($pdo);
    $ids = asr_tg_broadcast_report_ids($broadcastIds);
    $limit = max(1, min(50, $limit));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT r.id, r.broadcast_id, r.bot_id, r.subscriber_id, r.chat_id, r.status, r.attempts, r.last_error, r.updated_at, s.username, s.first_name, s.last_name, s.telegram_user_id, b.title AS bot_title
        FROM oca_telegram_bot_broadcast_recipients r
        LEFT JOIN oca_telegram_bot_subscribers s ON s.id = r.subscriber_id
        LEFT JOIN oca_telegram_bots b ON b.id = r.bot_id
        WHERE r.broadcast_id IN ({$placeholders}) AND r.status = 'failed'
        ORDER BY r.updated_at DESC, r.id DESC
        LIMIT {$limit}");
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_broadcast_history_where(int $botId = 0, array $filters = [], array &$params = []): string {
    $where = [];
    $params = [];

    $botIds = [];
    if (!empty($filters['bot_ids']) && is_array($filters['bot_ids'])) {
        $botIds = array_values(array_unique(array_filter(array_map('intval', $filters['bot_ids']), static fn($id) => $id > 0)));
    }
    if ($botIds) {
        $placeholders = implode(',', array_fill(0, count($botIds), '?'));
        $where[] = 'br.bot_id IN (' . $placeholders . ')';
        $params = array_merge($params, $botIds);
    } elseif ($botId > 0) {
        $where[] = 'br.bot_id = ?';
        $params[] = $botId;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'br.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $to = DateTime::createFromFormat('Y-m-d H:i:s', $dateTo . ' 00:00:00');
        if ($to instanceof DateTime) {
            $to->modify('+1 day');
            $where[] = 'br.created_at < ?';
            $params[] = $to->format('Y-m-d H:i:s');
        }
    }

    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function asr_tg_broadcasts_count(PDO $pdo, int $botId = 0, array $filters = []): int {
    asr_tg_repository_ensure_schema($pdo);
    $params = [];
    $whereSql = asr_tg_broadcast_history_where($botId, $filters, $params);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oca_telegram_bot_broadcasts br' . $whereSql);
    $stmt->execute($params);
    return max(0, (int)$stmt->fetchColumn());
}

function asr_tg_broadcasts_recent(PDO $pdo, int $botId = 0, int $limit = 50, array $filters = [], int $offset = 0): array {
    asr_tg_repository_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $params = [];
    $whereSql = asr_tg_broadcast_history_where($botId, $filters, $params);

    $sql = 'SELECT br.*, b.title AS bot_title, b.bot_username FROM oca_telegram_bot_broadcasts br JOIN oca_telegram_bots b ON b.id = br.bot_id';
    $sql .= $whereSql;
    $sql .= ' ORDER BY br.id DESC LIMIT ' . $offset . ', ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}


function asr_tg_custom_field_normalize_code(string $code, string $title = ''): string {
    $code = trim(mb_strtolower($code, 'UTF-8'));
    $code = preg_replace('/[^a-z0-9_]+/u', '_', $code);
    $code = trim((string)$code, '_');
    if ($code === '' && $title !== '') {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ];
        $tmp = strtr(mb_strtolower(trim($title), 'UTF-8'), $map);
        $tmp = preg_replace('/[^a-z0-9_]+/u', '_', $tmp);
        $code = trim((string)$tmp, '_');
    }
    if ($code === '') $code = 'field_' . substr(bin2hex(random_bytes(4)), 0, 8);
    if (!preg_match('/^[a-z]/', $code)) $code = 'f_' . $code;
    return substr($code, 0, 80);
}

function asr_tg_custom_fields_all(PDO $pdo, int $botId = 0, bool $activeOnly = false): array {
    try {
        asr_tg_repository_ensure_custom_fields_schema($pdo);
    } catch (Throwable $e) {
        if (!asr_tg_table_exists($pdo, 'oca_telegram_bot_custom_fields')) return [];
    }
    $where = ['bot_id = ?'];
    $params = [$botId];
    if ($activeOnly) $where[] = 'is_active = 1';
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_custom_fields WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order ASC, title ASC, id ASC');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_custom_field_save_from_post(PDO $pdo, array $post): int {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки полей.');
    asr_tg_repository_ensure_custom_fields_schema($pdo);

    $id = max(0, (int)($post['field_id'] ?? 0));
    $botId = max(0, (int)($post['bot_id'] ?? 0));
    $title = trim((string)($post['title'] ?? ''));
    $code = asr_tg_custom_field_normalize_code((string)($post['code'] ?? ''), $title);
    $type = trim((string)($post['field_type'] ?? 'text'));
    $allowed = ['text','number','date','datetime'];
    if (!in_array($type, $allowed, true)) $type = 'text';
    $sort = (int)($post['sort_order'] ?? 100);
    if ($title === '') throw new RuntimeException('Укажите название настраиваемого поля.');

    if ($id > 0) {
        $conflictStmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_custom_fields WHERE bot_id=? AND code=? AND id<>? LIMIT 1');
        $conflictStmt->execute([$botId, $code, $id]);
        if ((int)$conflictStmt->fetchColumn() > 0) {
            throw new RuntimeException('Поле с таким кодом уже есть. Укажите другой код.');
        }
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_custom_fields SET title=?, code=?, field_type=?, sort_order=?, is_active=1, updated_at=NOW() WHERE id=? AND bot_id=?');
        $stmt->execute([$title, $code, $type, $sort, $id, $botId]);
        return $id;
    }

    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_custom_fields (bot_id,title,code,field_type,sort_order,is_active)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            title = VALUES(title),
            field_type = VALUES(field_type),
            sort_order = VALUES(sort_order),
            is_active = 1,
            updated_at = NOW()");
    $stmt->execute([$botId, $title, $code, $type, $sort]);
    $newId = (int)$pdo->lastInsertId();
    if ($newId > 0) return $newId;

    $existingStmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_custom_fields WHERE bot_id=? AND code=? LIMIT 1');
    $existingStmt->execute([$botId, $code]);
    $existingId = (int)$existingStmt->fetchColumn();
    if ($existingId <= 0) throw new RuntimeException('Поле не сохранилось. Проверьте права БД на таблицу oca_telegram_bot_custom_fields.');
    return $existingId;
}

function asr_tg_custom_field_archive_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки полей.');
    $id = max(0, (int)($post['field_id'] ?? 0));
    $botId = max(0, (int)($post['bot_id'] ?? 0));
    if ($id <= 0) throw new RuntimeException('Поле не найдено.');
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_custom_fields SET is_active=0, updated_at=NOW() WHERE id=? AND bot_id=?');
    $stmt->execute([$id, $botId]);
}

function asr_tg_custom_field_restore_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки полей.');
    $id = max(0, (int)($post['field_id'] ?? 0));
    $botId = max(0, (int)($post['bot_id'] ?? 0));
    if ($id <= 0) throw new RuntimeException('Поле не найдено.');
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_custom_fields SET is_active=1, updated_at=NOW() WHERE id=? AND bot_id=?');
    $stmt->execute([$id, $botId]);
}


function asr_tg_custom_field_delete_from_post(PDO $pdo, array $post): void {
    if (!asr_tg_can('manage')) throw new RuntimeException('Недостаточно прав для настройки полей.');
    asr_tg_repository_ensure_custom_fields_schema($pdo);
    $id = max(0, (int)($post['field_id'] ?? 0));
    $botId = max(0, (int)($post['bot_id'] ?? 0));
    if ($id <= 0) throw new RuntimeException('Поле не найдено.');

    $activeStmt = $pdo->prepare('SELECT is_active FROM oca_telegram_bot_custom_fields WHERE id=? AND bot_id=? LIMIT 1');
    $activeStmt->execute([$id, $botId]);
    $isActive = $activeStmt->fetchColumn();
    if ($isActive === false) throw new RuntimeException('Поле не найдено.');
    if ((int)$isActive === 1) throw new RuntimeException('Сначала отправьте поле в архив, потом его можно удалить навсегда.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_custom_values WHERE field_id=?');
        $stmt->execute([$id]);
        $stmt = $pdo->prepare('DELETE FROM oca_telegram_bot_custom_fields WHERE id=? AND bot_id=?');
        $stmt->execute([$id, $botId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


function asr_tg_subscriber_related_ids(PDO $pdo, int $subscriberId, ?array $subscriber = null): array {
    if ($subscriberId <= 0) return [];
    if ($subscriber === null) {
        $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, 0);
    }
    if (!$subscriber) return [$subscriberId];
    $telegramUserId = (int)($subscriber['telegram_user_id'] ?? 0);
    if ($telegramUserId <= 0) return [$subscriberId];

    $stmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_subscribers WHERE telegram_user_id = ? ORDER BY id ASC');
    $stmt->execute([$telegramUserId]);
    $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    return $ids ?: [$subscriberId];
}

function asr_tg_subscriber_custom_values_get(PDO $pdo, int $subscriberId): array {
    asr_tg_repository_ensure_custom_fields_schema($pdo);
    if ($subscriberId <= 0) return [];

    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, 0);
    $relatedIds = asr_tg_subscriber_related_ids($pdo, $subscriberId, $subscriber ?: null);
    if (!$relatedIds) return [];

    $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_subscriber_custom_values WHERE subscriber_id IN ($placeholders) ORDER BY field_id ASC, updated_at DESC, subscriber_id DESC");
    $stmt->execute($relatedIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $fieldId = (int)($row['field_id'] ?? 0);
        if ($fieldId <= 0 || isset($map[$fieldId])) continue;
        $map[$fieldId] = $row;
    }
    return $map;
}

function asr_tg_custom_value_for_input(array $field, array $valuesMap): string {
    $fieldId = (int)($field['id'] ?? 0);
    $type = (string)($field['field_type'] ?? 'text');
    $row = $valuesMap[$fieldId] ?? [];
    if (!$row) return '';
    if ($type === 'number') {
        $value = $row['value_number'] ?? null;
        if ($value === null || $value === '') return '';
        return rtrim(rtrim((string)$value, '0'), '.');
    }
    if ($type === 'date') return (string)($row['value_date'] ?? '');
    if ($type === 'datetime') {
        $value = (string)($row['value_datetime'] ?? '');
        if ($value === '') return '';
        return str_replace(' ', 'T', substr($value, 0, 16));
    }
    return (string)($row['value_text'] ?? '');
}

function asr_tg_subscriber_custom_values_save(PDO $pdo, int $subscriberId, int $botId, array $values): void {
    asr_tg_repository_ensure_custom_fields_schema($pdo);
    if ($subscriberId <= 0 || $botId <= 0) throw new InvalidArgumentException('Подписчик не найден.');
    $subscriber = asr_tg_subscriber_find($pdo, $subscriberId, $botId);
    if (!$subscriber) throw new InvalidArgumentException('Подписчик не найден.');

    $relatedIds = asr_tg_subscriber_related_ids($pdo, $subscriberId, $subscriber);
    if (!$relatedIds) $relatedIds = [$subscriberId];

    $fields = asr_tg_custom_fields_all($pdo, 0, true);
    $fieldMap = [];
    foreach ($fields as $field) {
        $fieldMap[(int)($field['id'] ?? 0)] = $field;
    }

    $upsert = $pdo->prepare('INSERT INTO oca_telegram_bot_subscriber_custom_values
        (subscriber_id, field_id, value_text, value_number, value_date, value_datetime)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            value_text = VALUES(value_text),
            value_number = VALUES(value_number),
            value_date = VALUES(value_date),
            value_datetime = VALUES(value_datetime),
            updated_at = NOW()');
    $delete = $pdo->prepare('DELETE FROM oca_telegram_bot_subscriber_custom_values WHERE subscriber_id = ? AND field_id = ?');

    foreach ($fieldMap as $fieldId => $field) {
        $raw = $values[$fieldId] ?? '';
        if (is_array($raw)) $raw = '';
        $raw = trim((string)$raw);
        $type = (string)($field['field_type'] ?? 'text');
        if (!in_array($type, ['text','number','date','datetime'], true)) $type = 'text';

        if ($raw === '') {
            foreach ($relatedIds as $relatedId) {
                $delete->execute([(int)$relatedId, $fieldId]);
            }
            continue;
        }

        $valueText = null;
        $valueNumber = null;
        $valueDate = null;
        $valueDatetime = null;

        if ($type === 'number') {
            $normalized = str_replace(',', '.', $raw);
            if (!is_numeric($normalized)) {
                throw new InvalidArgumentException('Поле «' . (string)($field['title'] ?? '') . '» должно быть числом.');
            }
            $valueNumber = $normalized;
            $valueText = $raw;
        } elseif ($type === 'date') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                throw new InvalidArgumentException('Поле «' . (string)($field['title'] ?? '') . '» должно быть датой.');
            }
            [$y, $m, $d] = array_map('intval', explode('-', $raw));
            if (!checkdate($m, $d, $y)) {
                throw new InvalidArgumentException('Поле «' . (string)($field['title'] ?? '') . '» содержит некорректную дату.');
            }
            $valueDate = $raw;
            $valueText = $raw;
        } elseif ($type === 'datetime') {
            $normalized = str_replace('T', ' ', $raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
                $normalized .= ':00';
            }
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $normalized);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $normalized) {
                throw new InvalidArgumentException('Поле «' . (string)($field['title'] ?? '') . '» должно быть датой и временем.');
            }
            $valueDatetime = $normalized;
            $valueText = $raw;
        } else {
            $valueText = mb_substr($raw, 0, 5000, 'UTF-8');
        }

        foreach ($relatedIds as $relatedId) {
            $upsert->execute([(int)$relatedId, $fieldId, $valueText, $valueNumber, $valueDate, $valueDatetime]);
        }
    }
}

function asr_tg_variable_system_fields(): array {
    return [
        ['code'=>'first_name','title'=>'Имя','field_type'=>'text','source'=>'system'],
        ['code'=>'last_name','title'=>'Фамилия','field_type'=>'text','source'=>'system'],
        ['code'=>'username','title'=>'Username Telegram/VK','field_type'=>'text','source'=>'system'],
        ['code'=>'phone','title'=>'Телефон','field_type'=>'text','source'=>'system'],
        ['code'=>'email','title'=>'E-mail','field_type'=>'text','source'=>'system'],
        ['code'=>'telegram_user_id','title'=>'ID пользователя','field_type'=>'number','source'=>'system'],
    ];
}

function asr_tg_variables_catalog(PDO $pdo, int $botId = 0): array {
    $custom = array_map(static function(array $row): array {
        return [
            'code' => 'custom.' . (string)$row['code'],
            'title' => (string)$row['title'],
            'field_type' => (string)$row['field_type'],
            'source' => 'custom',
            'id' => (int)$row['id'],
        ];
    }, asr_tg_custom_fields_all($pdo, $botId, true));
    return array_merge(asr_tg_variable_system_fields(), $custom);
}

function asr_tg_repository_ensure_scenario_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenarios` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(190) NOT NULL,
        `description` TEXT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
        `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Almaty',
        `start_block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `updated_by` INT UNSIGNED NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `archived_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenarios_status` (`status`),
        KEY `idx_tg_scenarios_archived` (`archived_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!asr_tg_column_exists($pdo, 'oca_telegram_bot_scenarios', 'timezone')) {
        try {
            $pdo->exec("ALTER TABLE `oca_telegram_bot_scenarios` ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Almaty' AFTER `status`");
        } catch (Throwable $e) {
            // Если права БД не позволяют ALTER, остальные части сценариев должны продолжить работать.
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_bots` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `bot_id` INT UNSIGNED NOT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tg_scenario_bot` (`scenario_id`, `bot_id`),
        KEY `idx_tg_scenario_bots_bot` (`bot_id`),
        KEY `idx_tg_scenario_bots_enabled` (`scenario_id`, `is_enabled`),
        KEY `idx_tg_scenario_bots_default` (`scenario_id`, `is_default`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_blocks` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `type` VARCHAR(40) NOT NULL DEFAULT 'message',
        `title` VARCHAR(190) NOT NULL,
        `settings_json` JSON NULL,
        `position_x` INT NOT NULL DEFAULT 120,
        `position_y` INT NOT NULL DEFAULT 120,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_blocks_scenario` (`scenario_id`, `sort_order`),
        KEY `idx_tg_scenario_blocks_type` (`type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_links` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `from_block_id` BIGINT UNSIGNED NOT NULL,
        `to_block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `link_type` VARCHAR(40) NOT NULL DEFAULT 'next',
        `condition_json` JSON NULL,
        `button_text` VARCHAR(190) NULL DEFAULT NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_links_scenario` (`scenario_id`, `sort_order`),
        KEY `idx_tg_scenario_links_from` (`from_block_id`),
        KEY `idx_tg_scenario_links_to` (`to_block_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_block_cards` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `block_id` BIGINT UNSIGNED NOT NULL,
        `card_type` VARCHAR(40) NOT NULL DEFAULT 'text',
        `title` VARCHAR(190) NULL DEFAULT NULL,
        `body_text` MEDIUMTEXT NULL,
        `media_type` VARCHAR(40) NULL DEFAULT NULL,
        `media_url` VARCHAR(1000) NULL DEFAULT NULL,
        `media_file` VARCHAR(1000) NULL DEFAULT NULL,
        `settings_json` JSON NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_cards_block` (`block_id`, `sort_order`),
        KEY `idx_tg_scenario_cards_scenario` (`scenario_id`, `sort_order`),
        KEY `idx_tg_scenario_cards_type` (`card_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_card_buttons` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `block_id` BIGINT UNSIGNED NOT NULL,
        `card_id` BIGINT UNSIGNED NOT NULL,
        `button_type` VARCHAR(40) NOT NULL DEFAULT 'transition',
        `title` VARCHAR(190) NOT NULL,
        `url` VARCHAR(1000) NULL DEFAULT NULL,
        `target_block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `target_scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `settings_json` JSON NULL,
        `sort_order` INT NOT NULL DEFAULT 100,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_buttons_card` (`card_id`, `sort_order`),
        KEY `idx_tg_scenario_buttons_scenario` (`scenario_id`, `sort_order`),
        KEY `idx_tg_scenario_buttons_target_block` (`target_block_id`),
        KEY `idx_tg_scenario_buttons_target_scenario` (`target_scenario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_deeplinks` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `block_id` BIGINT UNSIGNED NOT NULL,
        `code` VARCHAR(80) NOT NULL,
        `title` VARCHAR(190) NULL DEFAULT NULL,
        `behavior` VARCHAR(40) NOT NULL DEFAULT 'jump',
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_tg_scenario_deeplink_code` (`code`),
        UNIQUE KEY `uniq_tg_scenario_deeplink_block` (`block_id`),
        KEY `idx_tg_scenario_deeplinks_scenario` (`scenario_id`, `is_enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_subscriber_scenarios` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NOT NULL,
        `telegram_user_id` BIGINT NOT NULL,
        `bot_id` INT UNSIGNED NOT NULL,
        `current_block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
        `next_run_at` DATETIME NULL DEFAULT NULL,
        `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `stopped_at` DATETIME NULL DEFAULT NULL,
        `finished_at` DATETIME NULL DEFAULT NULL,
        `last_error` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_sub_scenarios_due` (`status`, `next_run_at`),
        KEY `idx_tg_sub_scenarios_subscriber` (`subscriber_id`),
        KEY `idx_tg_sub_scenarios_user` (`telegram_user_id`),
        KEY `idx_tg_sub_scenarios_scenario_bot` (`scenario_id`, `bot_id`),
        KEY `idx_tg_sub_scenarios_active` (`scenario_id`, `telegram_user_id`, `bot_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_sent_messages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `block_id` BIGINT UNSIGNED NOT NULL,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NOT NULL,
        `telegram_user_id` BIGINT NULL DEFAULT NULL,
        `chat_id` VARCHAR(80) NOT NULL,
        `card_index` INT NOT NULL DEFAULT 0,
        `card_type` VARCHAR(40) NOT NULL DEFAULT 'text',
        `telegram_message_id` BIGINT NOT NULL,
        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        `delete_error` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_sent_lookup` (`scenario_id`, `subscriber_id`, `block_id`, `sent_at`),
        KEY `idx_tg_scenario_sent_message` (`bot_id`, `chat_id`, `telegram_message_id`),
        KEY `idx_tg_scenario_sent_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_yandex_metrika_events` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id` INT UNSIGNED NOT NULL,
        `subscriber_id` INT UNSIGNED NOT NULL,
        `telegram_user_id` BIGINT NULL DEFAULT NULL,
        `scenario_id` BIGINT UNSIGNED NOT NULL,
        `block_id` BIGINT UNSIGNED NOT NULL,
        `action_index` INT NOT NULL DEFAULT 0,
        `counter_id` VARCHAR(40) NOT NULL,
        `target` VARCHAR(120) NOT NULL,
        `client_id` VARCHAR(190) NULL DEFAULT NULL,
        `conversion_at` DATETIME NOT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `attempts` INT NOT NULL DEFAULT 0,
        `last_error` TEXT NULL,
        `payload_json` JSON NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `queued_at` DATETIME NULL DEFAULT NULL,
        `sent_at` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_tg_ym_events_due` (`status`, `created_at`),
        KEY `idx_tg_ym_events_counter` (`counter_id`, `target`, `status`),
        KEY `idx_tg_ym_events_scenario` (`scenario_id`, `block_id`, `created_at`),
        KEY `idx_tg_ym_events_subscriber` (`subscriber_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `oca_telegram_bot_scenario_events` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `subscriber_id` INT UNSIGNED NULL DEFAULT NULL,
        `telegram_user_id` BIGINT NULL DEFAULT NULL,
        `bot_id` INT UNSIGNED NULL DEFAULT NULL,
        `block_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `event_type` VARCHAR(80) NOT NULL,
        `event_text` TEXT NULL,
        `payload_json` JSON NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tg_scenario_events_scenario` (`scenario_id`, `created_at`),
        KEY `idx_tg_scenario_events_subscriber` (`subscriber_id`, `created_at`),
        KEY `idx_tg_scenario_events_user` (`telegram_user_id`, `created_at`),
        KEY `idx_tg_scenario_events_type` (`event_type`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function asr_tg_scenario_status_labels(): array {
    return [
        'draft' => 'Черновик',
        'active' => 'Активен',
        'paused' => 'Пауза',
        'archived' => 'Архив',
    ];
}

function asr_tg_scenarios_all(PDO $pdo, string $status = ''): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $statusLabels = asr_tg_scenario_status_labels();
    $where = '';
    $params = [];
    if ($status !== '' && isset($statusLabels[$status])) {
        $where = 'WHERE s.status = ?';
        $params[] = $status;
    }
    $sql = "SELECT s.*,
            COUNT(DISTINCT sb.bot_id) AS bots_count,
            SUM(CASE WHEN sb.is_enabled = 1 THEN 1 ELSE 0 END) AS enabled_bots_count,
            GROUP_CONCAT(DISTINCT CASE WHEN sb.is_enabled = 1 THEN CONCAT(COALESCE(b.title, CONCAT('Канал #', sb.bot_id)), IF(COALESCE(b.bot_username, '') <> '', CONCAT(' (@', b.bot_username, ')'), '')) ELSE NULL END ORDER BY b.title SEPARATOR '\n') AS enabled_bot_titles,
            COUNT(DISTINCT bl.id) AS blocks_count
        FROM oca_telegram_bot_scenarios s
        LEFT JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id
        LEFT JOIN oca_telegram_bots b ON b.id = sb.bot_id
        LEFT JOIN oca_telegram_bot_scenario_blocks bl ON bl.scenario_id = s.id
        {$where}
        GROUP BY s.id
        ORDER BY FIELD(s.status, 'active', 'draft', 'paused', 'archived'), s.updated_at DESC, s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_scenario_find(PDO $pdo, int $scenarioId): ?array {
    if ($scenarioId <= 0) return null;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenarios WHERE id = ? LIMIT 1');
    $stmt->execute([$scenarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function asr_tg_scenario_bot_ids(PDO $pdo, int $scenarioId, bool $enabledOnly = false): array {
    if ($scenarioId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $sql = 'SELECT bot_id FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ?';
    if ($enabledOnly) $sql .= ' AND is_enabled = 1';
    $sql .= ' ORDER BY is_default DESC, bot_id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$scenarioId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function asr_tg_scenario_default_bot_id(PDO $pdo, int $scenarioId): int {
    if ($scenarioId <= 0) return 0;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare('SELECT bot_id FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ? AND is_enabled = 1 ORDER BY is_default DESC, bot_id ASC LIMIT 1');
    $stmt->execute([$scenarioId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function asr_tg_scenario_bot_id(PDO $pdo, int $scenarioId): int {
    return asr_tg_scenario_default_bot_id($pdo, $scenarioId);
}

function asr_tg_scenario_normalize_single_bot(PDO $pdo, int $scenarioId): int {
    if ($scenarioId <= 0) return 0;
    asr_tg_repository_ensure_scenario_schema($pdo);
    $botId = asr_tg_scenario_default_bot_id($pdo, $scenarioId);
    if ($botId <= 0) {
        $stmt = $pdo->prepare('SELECT bot_id FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ? ORDER BY bot_id ASC LIMIT 1');
        $stmt->execute([$scenarioId]);
        $botId = (int)($stmt->fetchColumn() ?: 0);
    }
    $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ?')->execute([$scenarioId]);
    if ($botId > 0) {
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_bots (scenario_id, bot_id, is_enabled, is_default, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), NOW())');
        $stmt->execute([$scenarioId, $botId]);
    }
    return $botId;
}

function asr_tg_scenario_normalize_all_single_bot(PDO $pdo): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->query('SELECT scenario_id, COUNT(*) AS cnt FROM oca_telegram_bot_scenario_bots GROUP BY scenario_id HAVING cnt > 1');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        asr_tg_scenario_normalize_single_bot($pdo, (int)($row['scenario_id'] ?? 0));
    }
}


function asr_tg_scenario_timezone_normalize(string $timezone, string $fallback = 'Asia/Almaty'): string {
    $timezone = trim($timezone);
    if ($timezone === '') $timezone = $fallback;
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        try {
            new DateTimeZone($fallback);
            return $fallback;
        } catch (Throwable $ignored) {
            return 'Asia/Almaty';
        }
    }
}

function asr_tg_scenario_timezone(PDO $pdo, int $scenarioId = 0): string {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $fallback = defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty';
    $fallback = asr_tg_scenario_timezone_normalize($fallback, 'Asia/Almaty');
    if ($scenarioId <= 0 || !asr_tg_column_exists($pdo, 'oca_telegram_bot_scenarios', 'timezone')) return $fallback;
    try {
        $stmt = $pdo->prepare('SELECT timezone FROM oca_telegram_bot_scenarios WHERE id = ? LIMIT 1');
        $stmt->execute([$scenarioId]);
        return asr_tg_scenario_timezone_normalize((string)($stmt->fetchColumn() ?: ''), $fallback);
    } catch (Throwable $e) {
        return $fallback;
    }
}

function asr_tg_scenario_save(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['id'] ?? 0));
    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $status = (string)($data['status'] ?? 'draft');
    $timezone = asr_tg_scenario_timezone_normalize((string)($data['timezone'] ?? ''), defined('ASR_APP_TIMEZONE') ? (string)ASR_APP_TIMEZONE : 'Asia/Almaty');
    if (!isset(asr_tg_scenario_status_labels()[$status])) $status = 'draft';
    if ($title === '') throw new InvalidArgumentException('Укажите название сценария.');

    $botId = (int)($data['bot_id'] ?? $data['default_bot_id'] ?? 0);
    if ($botId <= 0) {
        $botIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['bot_ids'] ?? [])), static fn($id) => $id > 0)));
        $botId = (int)($botIds[0] ?? 0);
    }
    if ($status === 'active' && $botId <= 0) {
        throw new InvalidArgumentException('Для активного сценария нужно выбрать канал.');
    }

    $userId = function_exists('asr_current_user_id') ? (int)asr_current_user_id() : (int)($_SESSION['user_id'] ?? 0);
    $hasTimezoneColumn = asr_tg_column_exists($pdo, 'oca_telegram_bot_scenarios', 'timezone');
    if ($scenarioId > 0) {
        if ($hasTimezoneColumn) {
            $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET title = ?, description = ?, status = ?, timezone = ?, updated_by = ?, archived_at = CASE WHEN ? = \'archived\' THEN COALESCE(archived_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$title, $description !== '' ? $description : null, $status, $timezone, $userId ?: null, $status, $scenarioId]);
        } else {
            $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET title = ?, description = ?, status = ?, updated_by = ?, archived_at = CASE WHEN ? = \'archived\' THEN COALESCE(archived_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$title, $description !== '' ? $description : null, $status, $userId ?: null, $status, $scenarioId]);
        }
    } else {
        if ($hasTimezoneColumn) {
            $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenarios (title, description, status, timezone, created_by, updated_by, archived_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = \'archived\' THEN NOW() ELSE NULL END, NOW(), NOW())');
            $stmt->execute([$title, $description !== '' ? $description : null, $status, $timezone, $userId ?: null, $userId ?: null, $status]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenarios (title, description, status, created_by, updated_by, archived_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, CASE WHEN ? = \'archived\' THEN NOW() ELSE NULL END, NOW(), NOW())');
            $stmt->execute([$title, $description !== '' ? $description : null, $status, $userId ?: null, $userId ?: null, $status]);
        }
        $scenarioId = (int)$pdo->lastInsertId();
    }

    $previousBotIds = asr_tg_scenario_bot_ids($pdo, $scenarioId, false);

    $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_bots WHERE scenario_id = ?')->execute([$scenarioId]);
    if ($botId > 0) {
        $insert = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_bots (scenario_id, bot_id, is_enabled, is_default, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), NOW())');
        $insert->execute([$scenarioId, $botId]);
    }

    if (function_exists('asr_tg_bot_commands_detach_scenario_for_other_bots')) {
        asr_tg_bot_commands_detach_scenario_for_other_bots($pdo, $scenarioId, $botId);
    }

    return $scenarioId;
}


function asr_tg_bot_commands_detach_scenario_for_other_bots(PDO $pdo, int $scenarioId, int $currentBotId = 0): void {
    if ($scenarioId <= 0 || !asr_tg_table_exists($pdo, 'oca_telegram_bot_commands')) return;
    if ($currentBotId > 0) {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_commands SET scenario_id = NULL, step_id = NULL, updated_at = NOW() WHERE scenario_id = ? AND bot_id <> ?');
        $stmt->execute([$scenarioId, $currentBotId]);
    } else {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_commands SET scenario_id = NULL, step_id = NULL, updated_at = NOW() WHERE scenario_id = ?');
        $stmt->execute([$scenarioId]);
    }
}

function asr_tg_scenarios_for_bot(PDO $pdo, int $botId): array {
    if ($botId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare("SELECT s.* FROM oca_telegram_bot_scenarios s JOIN oca_telegram_bot_scenario_bots sb ON sb.scenario_id = s.id AND sb.is_enabled = 1 WHERE sb.bot_id = ? AND s.status <> 'archived' ORDER BY FIELD(s.status, 'active', 'draft', 'paused'), s.title ASC, s.id DESC");
    $stmt->execute([$botId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_scenario_blocks_select(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare("SELECT id, type, title, sort_order FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY CASE WHEN type = 'start' THEN 0 ELSE 1 END, sort_order ASC, id ASC");
    $stmt->execute([$scenarioId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_scenario_set_status(PDO $pdo, int $scenarioId, string $status): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0) throw new InvalidArgumentException('Сценарий не выбран.');
    if (!isset(asr_tg_scenario_status_labels()[$status])) throw new InvalidArgumentException('Некорректный статус сценария.');
    if ($status === 'active' && asr_tg_scenario_default_bot_id($pdo, $scenarioId) <= 0) {
        throw new InvalidArgumentException('Для запуска сценария нужно выбрать канал.');
    }
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET status = ?, archived_at = CASE WHEN ? = \'archived\' THEN COALESCE(archived_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $status, $scenarioId]);
}

function asr_tg_scenario_delete(PDO $pdo, int $scenarioId): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0) throw new InvalidArgumentException('Сценарий не выбран.');

    $scenario = asr_tg_scenario_find($pdo, $scenarioId);
    if (!$scenario) throw new InvalidArgumentException('Сценарий не найден.');
    if ((string)($scenario['status'] ?? '') !== 'archived') {
        throw new RuntimeException('Навсегда удалить можно только архивный сценарий.');
    }

    $pdo->beginTransaction();
    try {
        $tables = [
            'oca_telegram_bot_scenario_events',
            'oca_telegram_bot_subscriber_scenarios',
            'oca_telegram_bot_scenario_deeplinks',
            'oca_telegram_bot_scenario_card_buttons',
            'oca_telegram_bot_scenario_block_cards',
            'oca_telegram_bot_scenario_links',
            'oca_telegram_bot_scenario_blocks',
            'oca_telegram_bot_scenario_bots',
        ];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE scenario_id = ?");
            $stmt->execute([$scenarioId]);
        }
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenarios WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_blocks_all(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$scenarioId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_scenario_block_find(PDO $pdo, int $blockId, int $scenarioId = 0): ?array {
    if ($blockId <= 0) return null;
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE id = ? AND scenario_id = ? LIMIT 1');
        $stmt->execute([$blockId, $scenarioId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_blocks WHERE id = ? LIMIT 1');
        $stmt->execute([$blockId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function asr_tg_scenario_block_cards_count(PDO $pdo, int $blockId): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($blockId <= 0) return 0;
    $stmt = $pdo->prepare('SELECT settings_json FROM oca_telegram_bot_scenario_blocks WHERE id = ? LIMIT 1');
    $stmt->execute([$blockId]);
    $settings = json_decode((string)($stmt->fetchColumn() ?: ''), true);
    if (is_array($settings) && isset($settings['cards']) && is_array($settings['cards'])) {
        return count($settings['cards']);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM oca_telegram_bot_scenario_block_cards WHERE block_id = ?');
    $stmt->execute([$blockId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function asr_tg_scenario_block_type_labels(): array {
    return [
        'start' => 'Старт',
        'message' => 'Сообщение',
        'actions' => 'Действия',
        'delay' => 'Задержка',
        'condition' => 'Условие',
    ];
}

function asr_tg_scenario_status_classes(): array {
    return [
        'active' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
        'draft' => 'bg-orange-50 text-orange-700 border-orange-100',
        'paused' => 'bg-gray-50 text-gray-600 border-gray-100',
        'stopped' => 'bg-gray-50 text-gray-600 border-gray-100',
        'archived' => 'bg-slate-50 text-slate-500 border-slate-100',
    ];
}


function asr_tg_scenario_links_all(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$scenarioId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function asr_tg_scenario_ensure_start_block(PDO $pdo, int $scenarioId): ?array {
    if ($scenarioId <= 0) return null;
    asr_tg_repository_ensure_scenario_schema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type = 'start' ORDER BY id ASC LIMIT 1");
    $stmt->execute([$scenarioId]);
    $start = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $createdStartBlock = false;
    if (!$start) {
        $settingsJson = json_encode([
            'version' => 1,
            'start_mode' => 'bot_join',
            'title' => 'По кнопке «Начать»',
            'description' => 'Запуск сценария при подключении человека к боту.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($settingsJson === false) $settingsJson = '{"version":1,"start_mode":"bot_join"}';
        $insert = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'start', 'Старт', ?, 90, 140, 0, NOW(), NOW())");
        $insert->execute([$scenarioId, $settingsJson]);
        $startId = (int)$pdo->lastInsertId();
        $createdStartBlock = true;
        $start = asr_tg_scenario_block_find($pdo, $startId, $scenarioId);
    }

    $startId = (int)($start['id'] ?? 0);
    if ($startId > 0) {
        if ($createdStartBlock) {
            $pdo->prepare("UPDATE oca_telegram_bot_scenario_blocks SET position_x = position_x + 340, updated_at = NOW() WHERE scenario_id = ? AND type <> 'start' AND position_x < 360")->execute([$scenarioId]);
        }
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET start_block_id = ?, updated_at = NOW() WHERE id = ? AND (start_block_id IS NULL OR start_block_id = 0 OR start_block_id <> ?)')->execute([$startId, $scenarioId, $startId]);

        $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start' ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$scenarioId]);
        $firstMessageId = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT id FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = 'start' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$scenarioId, $startId]);
        $hasStartLink = (int)($stmt->fetchColumn() ?: 0) > 0;

        if (!$hasStartLink && $firstMessageId > 0) {
            $insert = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_links (scenario_id, from_block_id, to_block_id, link_type, condition_json, button_text, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'start', NULL, 'Начать', 10, NOW(), NOW())");
            $insert->execute([$scenarioId, $startId, $firstMessageId]);
        }
    }

    return $start;
}

function asr_tg_scenario_deeplink_code_column(PDO $pdo): string {
    asr_tg_repository_ensure_scenario_schema($pdo);

    // Не используем общий helper asr_tg_column_exists как единственный источник правды:
    // в некоторых сборках он возвращал false для реально существующей колонки code,
    // после чего код уходил в несуществующий token и ломал создание/вывод диплинков.
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `oca_telegram_bot_scenario_deeplinks`');
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        if (isset($columns['code'])) {
            return 'code';
        }
        if (isset($columns['token'])) {
            return 'token';
        }
    } catch (Throwable $e) {
        // Ниже дадим понятную ошибку вместо SQLSTATE Unknown column.
    }

    throw new RuntimeException('В таблице oca_telegram_bot_scenario_deeplinks не найдена колонка code для хранения диплинка.');
}

function asr_tg_scenario_deeplink_normalize_row(array $row): array {
    if (!isset($row['code']) && isset($row['token'])) {
        $row['code'] = $row['token'];
    }
    $row['code'] = trim((string)($row['code'] ?? ''));
    return $row;
}

function asr_tg_scenario_deeplink_url(PDO $pdo, int $scenarioId, array $deeplink): string {
    $code = trim((string)($deeplink['code'] ?? $deeplink['token'] ?? ''));
    if ($code === '') return '';
    $botId = asr_tg_scenario_default_bot_id($pdo, $scenarioId);
    $bot = $botId > 0 && function_exists('asr_tg_bot_find_light') ? asr_tg_bot_find_light($pdo, $botId) : null;
    $username = trim((string)($bot['bot_username'] ?? ''));
    $username = ltrim($username, '@');
    if ($username === '') return $code;
    return 'https://t.me/' . $username . '?start=' . rawurlencode($code);
}

function asr_tg_scenario_block_deeplink_find(PDO $pdo, int $scenarioId, int $blockId): ?array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $blockId <= 0) return null;
    $codeColumn = asr_tg_scenario_deeplink_code_column($pdo);
    $stmt = $pdo->prepare("SELECT *, `{$codeColumn}` AS code FROM oca_telegram_bot_scenario_deeplinks WHERE scenario_id = ? AND block_id = ? AND is_enabled = 1 ORDER BY id ASC LIMIT 1");
    $stmt->execute([$scenarioId, $blockId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? asr_tg_scenario_deeplink_normalize_row($row) : null;
}

function asr_tg_scenario_block_deeplinks_by_blocks(PDO $pdo, int $scenarioId, array $blockIds): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $blockIds), static fn($id) => $id > 0)));
    if ($scenarioId <= 0 || !$ids) return [];
    $codeColumn = asr_tg_scenario_deeplink_code_column($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT *, `{$codeColumn}` AS code FROM oca_telegram_bot_scenario_deeplinks WHERE scenario_id = ? AND block_id IN ({$placeholders}) AND is_enabled = 1 ORDER BY id ASC");
    $stmt->execute(array_merge([$scenarioId], $ids));
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row = asr_tg_scenario_deeplink_normalize_row($row);
        $blockId = (int)($row['block_id'] ?? 0);
        if ($blockId > 0 && !isset($out[$blockId])) {
            $row['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $row);
            $out[$blockId] = $row;
        }
    }
    return $out;
}

function asr_tg_scenario_ensure_block_deeplink(PDO $pdo, int $scenarioId, int $blockId, int $botId = 0): ?array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $blockId <= 0) return null;
    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block) throw new InvalidArgumentException('Блок сценария не найден.');
    if ((string)($block['type'] ?? '') === 'start') throw new InvalidArgumentException('Для стартового блока диплинк пока не создаём.');

    $existing = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
    if ($existing) {
        $existing['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $existing);
        return $existing;
    }

    $codeColumn = asr_tg_scenario_deeplink_code_column($pdo);
    $code = 'dl-' . $scenarioId . '-' . $blockId;
    $title = trim((string)($block['title'] ?? '')) ?: ('Блок #' . $blockId);
    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_deeplinks (scenario_id, block_id, `{$codeColumn}`, title, behavior, is_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, 'jump', 1, NOW(), NOW())");
    try {
        $stmt->execute([$scenarioId, $blockId, $code, $title]);
    } catch (Throwable $e) {
        $existing = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
        if ($existing) {
            $existing['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $existing);
            return $existing;
        }
        throw $e;
    }
    $created = asr_tg_scenario_block_deeplink_find($pdo, $scenarioId, $blockId);
    if ($created) $created['url'] = asr_tg_scenario_deeplink_url($pdo, $scenarioId, $created);
    $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
    return $created;
}

function asr_tg_scenario_block_deeplink_create_from_post(PDO $pdo, array $post): array {
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $blockId = max(0, (int)($post['block_id'] ?? 0));
    $row = asr_tg_scenario_ensure_block_deeplink($pdo, $scenarioId, $blockId);
    if (!$row) throw new RuntimeException('Не удалось создать диплинк.');
    return [
        'id' => (int)($row['id'] ?? 0),
        'block_id' => (int)($row['block_id'] ?? $blockId),
        'code' => (string)($row['code'] ?? ''),
        'url' => (string)($row['url'] ?? asr_tg_scenario_deeplink_url($pdo, $scenarioId, $row)),
    ];
}

function asr_tg_scenario_normalize_block_cards($payload): array {
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    if (isset($payload['cards']) && is_array($payload['cards'])) {
        $payload = $payload['cards'];
    }
    if (!is_array($payload)) return [];

    $allowedTypes = ['text' => true, 'image' => true, 'file' => true, 'audio' => true, 'video' => true, 'video_note' => true, 'gallery' => true, 'question' => true];
    $cards = [];
    foreach ($payload as $card) {
        if (!is_array($card)) continue;
        $type = (string)($card['type'] ?? 'text');
        if (!isset($allowedTypes[$type])) $type = 'text';
        $text = trim((string)($card['text'] ?? ''));
        $mediaUrl = trim((string)($card['media_url'] ?? ($card['url'] ?? '')));
        $mediaFilePath = trim((string)($card['media_file_path'] ?? ''));
        $mediaFileName = trim((string)($card['media_file_name'] ?? ''));
        $hasUpload = !empty($card['has_upload']);
        $uploadSlot = array_key_exists('upload_slot', $card) ? max(0, (int)$card['upload_slot']) : null;
        $galleryItems = [];
        if ($type === 'gallery' && isset($card['gallery_items']) && is_array($card['gallery_items'])) {
            foreach ($card['gallery_items'] as $galleryItem) {
                if (!is_array($galleryItem)) continue;
                $itemUrl = trim((string)($galleryItem['media_url'] ?? ($galleryItem['url'] ?? '')));
                $itemPath = trim((string)($galleryItem['media_file_path'] ?? ''));
                $itemName = trim((string)($galleryItem['media_file_name'] ?? ''));
                if ($itemUrl === '' && $itemPath === '') continue;
                $galleryItems[] = [
                    'media_url' => mb_substr($itemUrl, 0, 1000, 'UTF-8'),
                    'media_file_path' => mb_substr($itemPath, 0, 1000, 'UTF-8'),
                    'media_file_name' => mb_substr($itemName, 0, 255, 'UTF-8'),
                ];
                if (count($galleryItems) >= 10) break;
            }
        }
        $hasGalleryUpload = !empty($card['has_gallery_upload']);
        $galleryUploadSlot = array_key_exists('gallery_upload_slot', $card) ? max(0, (int)$card['gallery_upload_slot']) : null;
        $questionAnswers = [];
        if ($type === 'question' && isset($card['answers']) && is_array($card['answers'])) {
            foreach ($card['answers'] as $answer) {
                if (!is_array($answer)) continue;
                $answerText = trim((string)($answer['text'] ?? $answer['title'] ?? ''));
                if ($answerText === '') continue;
                $questionAnswers[] = [
                    'text' => mb_substr($answerText, 0, 128, 'UTF-8'),
                    'target_block_id' => max(0, (int)($answer['target_block_id'] ?? 0)),
                ];
                if (count($questionAnswers) >= 20) break;
            }
        }
        $buttonRows = [];
        $rawRows = $card['buttons'] ?? [];
        if (is_array($rawRows)) {
            foreach ($rawRows as $rawRow) {
                $rowIsList = is_array($rawRow) && array_keys($rawRow) === range(0, count($rawRow) - 1);
                $row = $rowIsList ? $rawRow : [$rawRow];
                $cleanRow = [];
                foreach ($row as $btn) {
                    if (!is_array($btn)) continue;
                    $btnText = trim((string)($btn['text'] ?? $btn['title'] ?? ''));
                    $btnUrl = trim((string)($btn['url'] ?? ''));
                    if ($btnText === '' && $btnUrl === '') continue;
                    $btnType = (string)($btn['type'] ?? 'url');
                    $btnType = $btnType === 'transition' ? 'transition' : 'url';
                    $targetBlockId = max(0, (int)($btn['target_block_id'] ?? 0));
                    $cleanRow[] = [
                        'type' => $btnType,
                        'text' => mb_substr($btnText, 0, 64, 'UTF-8'),
                        'url' => $btnType === 'url' ? mb_substr($btnUrl, 0, 1000, 'UTF-8') : '',
                        'target_block_id' => $btnType === 'transition' ? $targetBlockId : 0,
                    ];
                }
                if ($cleanRow) $buttonRows[] = $cleanRow;
            }
        }
        if ($type === 'text' && $text === '' && !$buttonRows) continue;
        if ($type === 'question' && $text === '' && !$questionAnswers) continue;
        if ($type === 'gallery' && !$galleryItems && $text === '' && !$buttonRows && !$hasGalleryUpload) continue;
        // Важно: медиа-карточка с выбранным файлом может пока не иметь media_url/media_file_path.
        // Раньше такая карточка выкидывалась здесь ДО обработки $_FILES, поэтому картинка/видео
        // «выбирались», но не сохранялись. has_upload сохраняет карточку до этапа apply_card_uploads().
        if ($type !== 'text' && $type !== 'gallery' && $type !== 'question' && $mediaUrl === '' && $mediaFilePath === '' && $text === '' && !$buttonRows && !$hasUpload) continue;
        $cleanCard = [
            'type' => $type,
            'text' => $text,
            'media_url' => mb_substr($mediaUrl, 0, 1000, 'UTF-8'),
            'buttons' => $buttonRows,
            'protect_content' => !empty($card['protect_content']),
        ];
        if ($type === 'question') {
            $cleanCard['answers'] = $questionAnswers;
            $cleanCard['save_field_code'] = mb_substr(trim((string)($card['save_field_code'] ?? $card['field_code'] ?? '')), 0, 100, 'UTF-8');
            $waitValue = max(1, min(999, (int)($card['wait_value'] ?? 24)));
            $waitUnit = (string)($card['wait_unit'] ?? 'hours');
            if (!in_array($waitUnit, ['minutes', 'hours', 'days'], true)) $waitUnit = 'hours';
            $cleanCard['wait_value'] = $waitValue;
            $cleanCard['wait_unit'] = $waitUnit;
            $cleanCard['enable_check'] = !empty($card['enable_check']);
            $cleanCard['remind_no_answer'] = !empty($card['remind_no_answer']);
            $cleanCard['remind_text'] = mb_substr(trim((string)($card['remind_text'] ?? '')), 0, 600, 'UTF-8');
            $cleanCard['remind_value'] = max(1, min(999, (int)($card['remind_value'] ?? 5)));
            $remindUnit = (string)($card['remind_unit'] ?? 'minutes');
            if (!in_array($remindUnit, ['minutes', 'hours', 'days'], true)) $remindUnit = 'minutes';
            $cleanCard['remind_unit'] = $remindUnit;
            $cleanCard['disable_web_page_preview'] = !empty($card['disable_web_page_preview']);
            $cleanCard['no_answer_target_block_id'] = max(0, (int)($card['no_answer_target_block_id'] ?? 0));
            $cleanCard['buttons'] = [];
        } elseif ($type === 'gallery') {
            $cleanCard['gallery_items'] = $galleryItems;
            $cleanCard['caption_enabled'] = !empty($card['caption_enabled']);
            if ($hasGalleryUpload) $cleanCard['has_gallery_upload'] = true;
            if ($galleryUploadSlot !== null) $cleanCard['gallery_upload_slot'] = $galleryUploadSlot;
        } elseif ($type !== 'text') {
            $cleanCard['caption_enabled'] = !empty($card['caption_enabled']);
            if (!$cleanCard['caption_enabled']) $cleanCard['text'] = '';
            if ($mediaFilePath !== '') $cleanCard['media_file_path'] = mb_substr($mediaFilePath, 0, 1000, 'UTF-8');
            if ($mediaFileName !== '') $cleanCard['media_file_name'] = mb_substr($mediaFileName, 0, 255, 'UTF-8');
            if ($hasUpload) $cleanCard['has_upload'] = true;
            if ($uploadSlot !== null) $cleanCard['upload_slot'] = $uploadSlot;
        }
        $cards[] = $cleanCard;
    }
    return $cards;
}

function asr_tg_scenario_card_uploaded_file(array $files, int $cardIndex, int $mediaIndex): array {
    $fieldByCard = 'card_media_file_' . $cardIndex;
    if (!empty($files[$fieldByCard]) && is_array($files[$fieldByCard])) {
        return $files[$fieldByCard];
    }
    if (function_exists('asr_tg_file_from_multi')) {
        $file = asr_tg_file_from_multi($files, 'card_media_file', $mediaIndex);
        if ($file) return $file;
    }
    return [];
}

function asr_tg_scenario_gallery_uploaded_files(array $files, int $cardIndex): array {
    $field = 'gallery_media_file_' . $cardIndex;
    if (empty($files[$field]) || !is_array($files[$field])) return [];
    $f = $files[$field];
    if (!is_array($f['name'] ?? null)) {
        return [$f];
    }
    $out = [];
    $count = count($f['name']);
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => $f['name'][$i] ?? '',
            'type' => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function asr_tg_scenario_validate_video_note_file(array $file): void {
    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'm4v'], true)) {
        throw new RuntimeException('Видео-заметка принимает только MP4 или M4V.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException('Файл видео-заметки больше 10 МБ. Выберите файл поменьше.');
    }
}

function asr_tg_scenario_validate_video_note_url(string $url): void {
    $url = trim($url);
    if ($url === '') return;
    if (!preg_match('~^https?://~i', $url)) {
        throw new RuntimeException('Ссылка на видео-заметку должна начинаться с http:// или https://.');
    }
    if (!preg_match('~\.(mp4|m4v)(\?|#|$)~i', $url)) {
        throw new RuntimeException('Ссылка на видео-заметку должна вести на MP4 или M4V.');
    }
}

function asr_tg_scenario_apply_card_uploads(array $cards, array $files = []): array {
    if (!$cards) return $cards;
    $mediaCursor = 0;
    foreach ($cards as $i => $card) {
        $type = (string)($card['type'] ?? 'text');
        if ($type === 'text') continue;
        if ($type === 'gallery') {
            $slot = array_key_exists('gallery_upload_slot', $card) ? (int)$card['gallery_upload_slot'] : (int)$i;
            $uploaded = asr_tg_scenario_gallery_uploaded_files($files, $slot);
            $items = isset($card['gallery_items']) && is_array($card['gallery_items']) ? array_values($card['gallery_items']) : [];
            $realUploads = 0;
            foreach ($uploaded as $file) {
                $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                $realUploads++;
                if ($error !== UPLOAD_ERR_OK) {
                    $message = function_exists('asr_tg_upload_error_message') ? asr_tg_upload_error_message($error) : 'Картинка галереи не была загружена.';
                    throw new RuntimeException($message);
                }
                $original = (string)($file['name'] ?? '');
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
                    throw new RuntimeException('Галерея принимает только изображения: JPG, PNG, WEBP или GIF.');
                }
                if (!function_exists('asr_tg_save_broadcast_media')) {
                    throw new RuntimeException('Механизм загрузки файлов недоступен. Проверьте service.php.');
                }
                $media = asr_tg_save_broadcast_media($file);
                if (!$media) continue;
                $mediaType = (string)($media['media_type'] ?? '');
                if (!in_array($mediaType, ['photo','image'], true)) {
                    throw new RuntimeException('Галерея принимает только изображения.');
                }
                $items[] = [
                    'media_url' => (string)($media['media_url'] ?? ''),
                    'media_file_path' => (string)($media['media_file_path'] ?? ''),
                    'media_file_name' => (string)($media['media_file_name'] ?? ''),
                ];
                if (count($items) >= 10) break;
            }
            if (!empty($card['has_gallery_upload']) && $realUploads === 0 && !$items) {
                throw new RuntimeException('Картинки выбраны в форме, но не дошли до сервера. Обновите страницу и попробуйте ещё раз.');
            }
            $cards[$i]['gallery_items'] = $items;
            unset($cards[$i]['has_gallery_upload'], $cards[$i]['gallery_upload_slot']);
            continue;
        }

        // Сначала пробуем точное имя поля по индексу карточки, потом старый массив card_media_file[].
        // Это защищает от рассинхрона, когда в блоке смешаны текстовые и медиа-карточки.
        $uploadSlot = array_key_exists('upload_slot', $card) ? (int)$card['upload_slot'] : (int)$i;
        $file = asr_tg_scenario_card_uploaded_file($files, $uploadSlot, $mediaCursor);
        $mediaCursor++;
        if ($type === 'video_note') {
            asr_tg_scenario_validate_video_note_url((string)($card['media_url'] ?? ''));
        }
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            // Оставляем существующее медиа как есть. Если карточка новая и файл обещан JS-ом,
            // но в $_FILES его нет, отдаём понятную ошибку вместо молчаливого «ничего не изменилось».
            if (!empty($card['has_upload']) && empty($card['media_file_path']) && empty($card['media_url'])) {
                throw new RuntimeException('Файл выбран в форме, но не дошёл до сервера. Обновите страницу и попробуйте ещё раз.');
            }
            unset($cards[$i]['has_upload'], $cards[$i]['upload_slot']);
            continue;
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = function_exists('asr_tg_upload_error_message')
                ? asr_tg_upload_error_message((int)$file['error'])
                : 'Файл не был загружен. Проверьте размер и тип файла.';
            throw new RuntimeException($message);
        }
        if ($type === 'video_note') {
            asr_tg_scenario_validate_video_note_file($file);
        }
        if (!function_exists('asr_tg_save_broadcast_media')) {
            throw new RuntimeException('Механизм загрузки файлов недоступен. Проверьте service.php.');
        }
        $media = asr_tg_save_broadcast_media($file);
        if (!$media) continue;
        $mediaType = (string)($media['media_type'] ?? $type);
        if ($mediaType === 'photo') $mediaType = 'image';
        if ($mediaType === 'document') $mediaType = 'file';
        if ($type === 'video_note') {
            $mediaType = 'video_note';
        } elseif (!in_array($mediaType, ['image','file','audio','video'], true)) {
            $mediaType = $type;
        }
        $cards[$i]['type'] = $mediaType;
        $cards[$i]['media_url'] = (string)($media['media_url'] ?? '');
        $cards[$i]['media_file_path'] = (string)($media['media_file_path'] ?? '');
        $cards[$i]['media_file_name'] = (string)($media['media_file_name'] ?? '');
        unset($cards[$i]['has_upload'], $cards[$i]['upload_slot']);
    }
    return $cards;
}



function asr_tg_scenario_condition_rule_summary(array $rule): string {
    $type = (string)($rule['type'] ?? '');
    $operator = (string)($rule['operator'] ?? '');
    if ($type === 'tag') {
        $name = trim((string)($rule['tag_name'] ?? 'тег'));
        return ($operator === 'not_has_tag' ? 'Нет тега: ' : 'Есть тег: ') . $name;
    }
    if ($type === 'channel') {
        $name = trim((string)($rule['channel_name'] ?? 'канал'));
        return ($operator === 'not_has_channel' ? 'Не подписан на: ' : 'Подписан на: ') . $name;
    }
    if ($type === 'current_date') {
        $date = trim((string)($rule['value'] ?? $rule['date'] ?? ''));
        $label = $operator === 'eq' ? 'Текущая дата = ' : ($operator === 'lte' ? 'Текущая дата ≤ ' : 'Текущая дата ≥ ');
        return $label . $date;
    }
    if ($type === 'current_time') {
        $time = trim((string)($rule['value'] ?? $rule['time'] ?? '00:00'));
        return ($operator === 'lte' ? 'Текущее время ≤ ' : 'Текущее время ≥ ') . $time;
    }
    if ($type === 'weekday') {
        $days = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
        $day = (int)($rule['value'] ?? $rule['weekday'] ?? 0);
        $title = $days[$day] ?? 'день недели';
        return ($operator === 'ne' ? 'День недели ≠ ' : 'День недели = ') . $title;
    }
    $fieldTitle = trim((string)($rule['field_title'] ?? $rule['param_label'] ?? $rule['field_code'] ?? 'поле'));
    $value = trim((string)($rule['value'] ?? ''));
    $map = [
        'equals' => ' = ', 'not_equals' => ' ≠ ', 'contains' => ' содержит ', 'not_contains' => ' не содержит ',
        'is_empty' => ' не заполнено', 'is_filled' => ' заполнено',
        'eq' => ' = ', 'ne' => ' ≠ ', 'gt' => ' > ', 'gte' => ' ≥ ', 'lt' => ' < ', 'lte' => ' ≤ ',
    ];
    if (in_array($operator, ['is_empty','is_filled'], true)) return $fieldTitle . ($map[$operator] ?? '');
    return $fieldTitle . ($map[$operator] ?? ' ') . $value;
}

function asr_tg_scenario_condition_field_catalog(PDO $pdo): array {
    $fields = [
        'first_name' => ['code' => 'first_name', 'title' => 'Имя', 'field_type' => 'text', 'source' => 'system'],
        'last_name' => ['code' => 'last_name', 'title' => 'Фамилия', 'field_type' => 'text', 'source' => 'system'],
        'username' => ['code' => 'username', 'title' => 'Username', 'field_type' => 'text', 'source' => 'system'],
        'phone' => ['code' => 'phone', 'title' => 'Телефон', 'field_type' => 'text', 'source' => 'system'],
        'email' => ['code' => 'email', 'title' => 'Email', 'field_type' => 'text', 'source' => 'system'],
    ];
    try {
        foreach (asr_tg_custom_fields_all($pdo, 0, true) as $field) {
            $code = trim((string)($field['code'] ?? ''));
            if ($code === '') continue;
            $type = (string)($field['field_type'] ?? 'text');
            if (!in_array($type, ['text','number','date','datetime'], true)) $type = 'text';
            $fields[$code] = ['code' => $code, 'title' => trim((string)($field['title'] ?? $code)) ?: $code, 'field_type' => $type, 'source' => 'custom'];
        }
    } catch (Throwable $e) {}
    return $fields;
}

function asr_tg_scenario_condition_parameter_catalog(PDO $pdo): array {
    $params = [
        'special:tag' => ['key' => 'special:tag', 'title' => 'Тег', 'param_type' => 'tag', 'source' => 'special'],
        'special:current_date' => ['key' => 'special:current_date', 'title' => 'Текущая дата', 'param_type' => 'current_date', 'source' => 'special'],
        'special:current_time' => ['key' => 'special:current_time', 'title' => 'Текущее время', 'param_type' => 'current_time', 'source' => 'special'],
        'special:weekday' => ['key' => 'special:weekday', 'title' => 'День недели', 'param_type' => 'weekday', 'source' => 'special'],
        'special:channel' => ['key' => 'special:channel', 'title' => 'Подписан на канал', 'param_type' => 'channel', 'source' => 'special'],
    ];
    foreach (asr_tg_scenario_condition_field_catalog($pdo) as $field) {
        $code = trim((string)($field['code'] ?? ''));
        if ($code === '') continue;
        $source = (string)($field['source'] ?? 'system');
        $prefix = $source === 'custom' ? 'custom:' : 'field:';
        $type = (string)($field['field_type'] ?? 'text');
        if (!in_array($type, ['text','number','date','datetime'], true)) $type = 'text';
        $params[$prefix . $code] = [
            'key' => $prefix . $code,
            'title' => trim((string)($field['title'] ?? $code)) ?: $code,
            'param_type' => $type,
            'source' => $source,
            'field_code' => $code,
            'field_type' => $type,
        ];
    }
    return $params;
}

function asr_tg_scenario_condition_value_for_type(array $data, string $type, int $i): string {
    $pick = static function(string $key) use ($data, $i): string {
        $list = is_array($data[$key] ?? null) ? $data[$key] : [];
        return trim((string)($list[$i] ?? ''));
    };
    if ($type === 'tag') return (string)max(0, (int)$pick('condition_value_tag'));
    if ($type === 'channel') return (string)max(0, (int)$pick('condition_value_channel'));
    if ($type === 'weekday') return (string)max(0, (int)$pick('condition_value_weekday'));
    if ($type === 'current_time') return $pick('condition_value_time');
    if ($type === 'current_date' || $type === 'date') return $pick('condition_value_date');
    if ($type === 'datetime') return $pick('condition_value_datetime');
    if ($type === 'number') return $pick('condition_value_number');
    return $pick('condition_value_text');
}

function asr_tg_scenario_normalize_condition_settings(PDO $pdo, array $data): array {
    $matchMode = (string)($data['condition_match_mode'] ?? $data['match_mode'] ?? 'all');
    if (!in_array($matchMode, ['all', 'any'], true)) $matchMode = 'all';

    $parameters = asr_tg_scenario_condition_parameter_catalog($pdo);
    $tagNames = [];
    try {
        foreach (asr_tg_tags_all_light($pdo, 0) as $tag) {
            $tagId = (int)($tag['id'] ?? 0);
            if ($tagId > 0) $tagNames[$tagId] = trim((string)($tag['name'] ?? 'Тег #' . $tagId)) ?: ('Тег #' . $tagId);
        }
    } catch (Throwable $e) {}
    $channelNames = [];
    try {
        foreach (asr_tg_bots_all_light($pdo) as $bot) {
            $botId = (int)($bot['id'] ?? 0);
            if ($botId > 0) $channelNames[$botId] = trim((string)($bot['title'] ?? $bot['name'] ?? 'Канал #' . $botId)) ?: ('Канал #' . $botId);
        }
    } catch (Throwable $e) {}

    $rules = [];
    $invalidRows = 0;

    $paramKeys = is_array($data['condition_param_key'] ?? null) ? $data['condition_param_key'] : [];
    if ($paramKeys) {
        $operators = is_array($data['condition_operator'] ?? null) ? $data['condition_operator'] : [];
        $value2s = is_array($data['condition_value2'] ?? null) ? $data['condition_value2'] : [];
        $limit = min(20, count($paramKeys));
        for ($i = 0; $i < $limit; $i++) {
            if (count($rules) >= 15) { $invalidRows++; continue; }
            $paramKey = trim((string)($paramKeys[$i] ?? ''));
            if ($paramKey === '' || !isset($parameters[$paramKey])) { $invalidRows++; continue; }
            $param = $parameters[$paramKey];
            $paramType = (string)($param['param_type'] ?? 'text');
            $operator = trim((string)($operators[$i] ?? ''));
            $value = asr_tg_scenario_condition_value_for_type($data, $paramType, $i);
            $value2 = trim((string)($value2s[$i] ?? ''));
            $rule = [
                'param_key' => $paramKey,
                'param_label' => (string)($param['title'] ?? $paramKey),
                'param_type' => $paramType,
                'operator' => $operator,
                'value' => $value,
                'value2' => $value2,
            ];

            if ($paramType === 'tag') {
                if (!in_array($operator, ['has_tag','not_has_tag'], true)) $operator = 'has_tag';
                $tagId = max(0, (int)$value);
                if ($tagId <= 0 || !isset($tagNames[$tagId])) { $invalidRows++; continue; }
                $rule['type'] = 'tag'; $rule['operator'] = $operator; $rule['tag_id'] = $tagId; $rule['tag_name'] = $tagNames[$tagId]; $rule['value'] = (string)$tagId;
            } elseif ($paramType === 'channel') {
                if (!in_array($operator, ['has_channel','not_has_channel'], true)) $operator = 'has_channel';
                $botId = max(0, (int)$value);
                if ($botId <= 0 || !isset($channelNames[$botId])) { $invalidRows++; continue; }
                $rule['type'] = 'channel'; $rule['operator'] = $operator; $rule['bot_id'] = $botId; $rule['channel_name'] = $channelNames[$botId]; $rule['value'] = (string)$botId;
            } elseif ($paramType === 'current_date') {
                if (!in_array($operator, ['eq','gte','lte'], true)) $operator = 'gte';
                if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) { $invalidRows++; continue; }
                [$yy, $mo, $dd] = array_map('intval', explode('-', $value));
                if (!checkdate($mo, $dd, $yy)) { $invalidRows++; continue; }
                $value = sprintf('%04d-%02d-%02d', $yy, $mo, $dd);
                $rule['type'] = 'current_date'; $rule['operator'] = $operator; $rule['date'] = $value; $rule['value'] = $value;
            } elseif ($paramType === 'current_time') {
                if (!in_array($operator, ['gte','lte'], true)) $operator = 'gte';
                if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $value)) { $invalidRows++; continue; }
                [$hh, $mm] = array_map('intval', explode(':', $value));
                $value = sprintf('%02d:%02d', $hh, $mm);
                $rule['type'] = 'current_time'; $rule['operator'] = $operator; $rule['time'] = $value; $rule['value'] = $value;
            } elseif ($paramType === 'weekday') {
                if (!in_array($operator, ['eq','ne'], true)) $operator = 'eq';
                $weekday = max(0, (int)$value);
                if ($weekday < 1 || $weekday > 7) { $invalidRows++; continue; }
                $rule['type'] = 'weekday'; $rule['operator'] = $operator; $rule['weekday'] = $weekday; $rule['value'] = (string)$weekday;
            } else {
                $fieldCode = trim((string)($param['field_code'] ?? ''));
                if ($fieldCode === '') { $invalidRows++; continue; }
                $rule['field_code'] = $fieldCode;
                $rule['field_title'] = (string)($param['title'] ?? $fieldCode);
                $rule['field_type'] = $paramType;
                $rule['field_source'] = (string)($param['source'] ?? 'system');
                if ($paramType === 'number') {
                    if (!in_array($operator, ['eq','ne','gt','gte','lt','lte','is_empty','is_filled'], true)) $operator = 'eq';
                    if (!in_array($operator, ['is_empty','is_filled'], true)) {
                        $normalizedNumber = str_replace([' ', ','], ['', '.'], $value);
                        if ($normalizedNumber === '' || !preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $normalizedNumber)) { $invalidRows++; continue; }
                        $value = $normalizedNumber;
                    } else {
                        $value = '';
                    }
                    $rule['type'] = 'field_number';
                } elseif ($paramType === 'date') {
                    if (!in_array($operator, ['eq','ne','gt','gte','lt','lte','is_empty','is_filled'], true)) $operator = 'eq';
                    if (!in_array($operator, ['is_empty','is_filled'], true)) {
                        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) { $invalidRows++; continue; }
                        [$yy, $mo, $dd] = array_map('intval', explode('-', $value));
                        if (!checkdate($mo, $dd, $yy)) { $invalidRows++; continue; }
                        $value = sprintf('%04d-%02d-%02d', $yy, $mo, $dd);
                    } else {
                        $value = '';
                    }
                    $rule['type'] = 'field_date';
                } elseif ($paramType === 'datetime') {
                    if (!in_array($operator, ['eq','ne','gt','gte','lt','lte','is_empty','is_filled'], true)) $operator = 'eq';
                    if (!in_array($operator, ['is_empty','is_filled'], true)) {
                        if (!preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$~', $value)) { $invalidRows++; continue; }
                    } else {
                        $value = '';
                    }
                    $rule['type'] = 'field_datetime';
                } else {
                    if (!in_array($operator, ['equals','not_equals','contains','not_contains','is_empty','is_filled'], true)) $operator = 'equals';
                    if (!in_array($operator, ['is_empty','is_filled'], true) && $value === '') { $invalidRows++; continue; }
                    if (in_array($operator, ['is_empty','is_filled'], true)) $value = '';
                    $rule['type'] = 'field_text';
                }
                $rule['operator'] = $operator;
                $rule['value'] = $value;
            }
            $rule['summary'] = asr_tg_scenario_condition_rule_summary($rule);
            $rules[] = $rule;
        }
    } else {
        $types = $data['condition_type'] ?? [];
        if (!is_array($types)) $types = [];
        $operators = is_array($data['condition_operator'] ?? null) ? $data['condition_operator'] : [];
        $fields = is_array($data['condition_field_code'] ?? null) ? $data['condition_field_code'] : [];
        $values = is_array($data['condition_value'] ?? null) ? $data['condition_value'] : [];
        $tagIds = is_array($data['condition_tag_id'] ?? null) ? $data['condition_tag_id'] : [];
        $times = is_array($data['condition_time'] ?? null) ? $data['condition_time'] : [];
        $dates = is_array($data['condition_date'] ?? null) ? $data['condition_date'] : [];
        $weekdays = is_array($data['condition_weekday'] ?? null) ? $data['condition_weekday'] : [];
        $fieldCatalog = asr_tg_scenario_condition_field_catalog($pdo);
        $limit = min(20, count($types));
        for ($i = 0; $i < $limit; $i++) {
            if (count($rules) >= 15) { $invalidRows++; continue; }
            $type = (string)($types[$i] ?? 'field_text');
            if (!in_array($type, ['tag','field_text','field_number','current_date','current_time','weekday'], true)) $type = 'field_text';
            $operator = trim((string)($operators[$i] ?? ''));
            $fieldCode = trim((string)($fields[$i] ?? ''));
            $value = trim((string)($values[$i] ?? ''));
            $tagId = max(0, (int)($tagIds[$i] ?? 0));
            $time = trim((string)($times[$i] ?? ''));
            $date = trim((string)($dates[$i] ?? ''));
            $weekday = max(0, (int)($weekdays[$i] ?? 0));
            if ($type === 'tag') {
                if (!in_array($operator, ['has_tag','not_has_tag'], true)) $operator = 'has_tag';
                if ($tagId <= 0 || !isset($tagNames[$tagId])) { $invalidRows++; continue; }
                $rule = ['type' => 'tag', 'param_key' => 'special:tag', 'param_label' => 'Тег', 'param_type' => 'tag', 'operator' => $operator, 'tag_id' => $tagId, 'tag_name' => $tagNames[$tagId], 'value' => (string)$tagId];
                $rule['summary'] = asr_tg_scenario_condition_rule_summary($rule); $rules[] = $rule; continue;
            }
            if ($type === 'current_date') {
                if (!in_array($operator, ['eq','gte','lte'], true)) $operator = 'gte';
                if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) { $invalidRows++; continue; }
                [$yy, $mo, $dd] = array_map('intval', explode('-', $date));
                if (!checkdate($mo, $dd, $yy)) { $invalidRows++; continue; }
                $value = sprintf('%04d-%02d-%02d', $yy, $mo, $dd);
                $rule = ['type' => 'current_date', 'param_key' => 'special:current_date', 'param_label' => 'Текущая дата', 'param_type' => 'current_date', 'operator' => $operator, 'date' => $value, 'value' => $value];
                $rule['summary'] = asr_tg_scenario_condition_rule_summary($rule); $rules[] = $rule; continue;
            }
            if ($type === 'current_time') {
                if (!in_array($operator, ['gte','lte'], true)) $operator = 'gte';
                if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) { $invalidRows++; continue; }
                [$hh, $mm] = array_map('intval', explode(':', $time));
                $value = sprintf('%02d:%02d', $hh, $mm);
                $rule = ['type' => 'current_time', 'param_key' => 'special:current_time', 'param_label' => 'Текущее время', 'param_type' => 'current_time', 'operator' => $operator, 'time' => $value, 'value' => $value];
                $rule['summary'] = asr_tg_scenario_condition_rule_summary($rule); $rules[] = $rule; continue;
            }
            if ($type === 'weekday') {
                if (!in_array($operator, ['eq','ne'], true)) $operator = 'eq';
                if ($weekday < 1 || $weekday > 7) { $invalidRows++; continue; }
                $rule = ['type' => 'weekday', 'param_key' => 'special:weekday', 'param_label' => 'День недели', 'param_type' => 'weekday', 'operator' => $operator, 'weekday' => $weekday, 'value' => (string)$weekday];
                $rule['summary'] = asr_tg_scenario_condition_rule_summary($rule); $rules[] = $rule; continue;
            }
            if ($fieldCode === '' || !isset($fieldCatalog[$fieldCode])) { $invalidRows++; continue; }
            $field = $fieldCatalog[$fieldCode];
            $fieldType = (string)($field['field_type'] ?? 'text');
            $source = (string)($field['source'] ?? 'system');
            if ($type === 'field_number') $fieldType = 'number';
            $paramKey = ($source === 'custom' ? 'custom:' : 'field:') . $fieldCode;
            $paramData = ['condition_match_mode' => $matchMode, 'condition_param_key' => [$paramKey], 'condition_operator' => [$operator], 'condition_value_text' => [$value], 'condition_value_number' => [$value], 'condition_value_date' => [$value], 'condition_value_datetime' => [$value], 'condition_value_time' => [$value], 'condition_value_weekday' => [$value], 'condition_value_tag' => [$value], 'condition_value_channel' => [$value], 'condition_value2' => ['']];
            $normalized = asr_tg_scenario_normalize_condition_settings($pdo, $paramData);
            if (!empty($normalized['conditions'][0])) $rules[] = $normalized['conditions'][0]; else $invalidRows++;
        }
    }

    return [
        'version' => 2,
        'condition_match_mode' => $matchMode,
        'conditions' => $rules,
        'condition_valid' => count($rules) > 0,
        'invalid_rows' => $invalidRows,
        'max_conditions' => 15,
        'runtime_plan' => ['enabled' => true, 'prepared_for' => 'condition_runner'],
    ];
}

function asr_tg_scenario_condition_block_save(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($blockId > 0 && !asr_tg_scenario_block_find($pdo, $blockId, $scenarioId)) throw new InvalidArgumentException('Блок сценария не найден.');
    $title = trim((string)($data['block_title'] ?? '')) ?: 'Условие';
    $settings = asr_tg_scenario_normalize_condition_settings($pdo, $data);
    $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить данные условия.');
    if ($blockId > 0) {
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_scenario_blocks SET type = 'condition', title = ?, settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?");
        $stmt->execute([$title, $settingsJson, $blockId, $scenarioId]);
        return $blockId;
    }
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start'");
    $stmt->execute([$scenarioId]);
    $blockCount = (int)($stmt->fetchColumn() ?: 0);
    $positionX = 430 + (($blockCount % 4) * 330);
    $positionY = 140 + ((int)floor($blockCount / 4) * 250);
    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'condition', ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$scenarioId, $title, $settingsJson, $positionX, $positionY, $sortOrder]);
    $blockId = (int)$pdo->lastInsertId();
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}


function asr_tg_scenario_message_blocks_for_delete_action(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) return [];
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stmt = $pdo->prepare("SELECT id, title, type, sort_order FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type = 'message' ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$scenarioId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') $title = 'Сообщение';
        $out[] = [
            'id' => $id,
            'title' => $title,
            'label' => $title . ' — Сообщение #' . $id,
            'ref' => 'Сообщение #' . $id,
        ];
    }
    return $out;
}

function asr_tg_scenario_message_block_labels(PDO $pdo, int $scenarioId, array $blockIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $blockIds), static fn($id) => $id > 0)));
    if (!$ids || $scenarioId <= 0) return [];
    $map = [];
    foreach (asr_tg_scenario_message_blocks_for_delete_action($pdo, $scenarioId) as $row) {
        $map[(int)$row['id']] = (string)$row['label'];
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) $out[$id] = $map[$id];
    }
    return $out;
}

function asr_tg_scenario_sent_message_record(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    $botId = max(0, (int)($data['bot_id'] ?? 0));
    $subscriberId = max(0, (int)($data['subscriber_id'] ?? 0));
    $messageId = max(0, (int)($data['telegram_message_id'] ?? 0));
    $chatId = trim((string)($data['chat_id'] ?? ''));
    if ($scenarioId <= 0 || $blockId <= 0 || $botId <= 0 || $subscriberId <= 0 || $messageId <= 0 || $chatId === '') return 0;
    $telegramUserId = null;
    try {
        $stmt = $pdo->prepare('SELECT telegram_user_id FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1');
        $stmt->execute([$subscriberId]);
        $rawUserId = $stmt->fetchColumn();
        if ($rawUserId !== false && $rawUserId !== null && $rawUserId !== '') $telegramUserId = (int)$rawUserId;
    } catch (Throwable $ignored) {}
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_sent_messages
        (scenario_id, block_id, bot_id, subscriber_id, telegram_user_id, chat_id, card_index, card_type, telegram_message_id, sent_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $scenarioId,
        $blockId,
        $botId,
        $subscriberId,
        $telegramUserId,
        $chatId,
        max(0, (int)($data['card_index'] ?? 0)),
        mb_substr(trim((string)($data['card_type'] ?? 'text')), 0, 40, 'UTF-8') ?: 'text',
        $messageId,
    ]);
    return (int)$pdo->lastInsertId();
}



function asr_tg_yandex_metrika_event_enqueue(PDO $pdo, array $data): array {
    asr_tg_repository_ensure_scenario_schema($pdo);

    $botId = max(0, (int)($data['bot_id'] ?? 0));
    $subscriberId = max(0, (int)($data['subscriber_id'] ?? 0));
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    $actionIndex = max(0, (int)($data['action_index'] ?? 0));
    $counterId = mb_substr(trim((string)($data['counter_id'] ?? '')), 0, 40, 'UTF-8');
    $target = mb_substr(trim((string)($data['target'] ?? '')), 0, 120, 'UTF-8');
    $clientId = mb_substr(trim((string)($data['client_id'] ?? '')), 0, 190, 'UTF-8');
    $status = trim((string)($data['status'] ?? 'pending'));
    if (!in_array($status, ['pending','skipped','failed'], true)) $status = 'pending';
    $lastError = mb_substr(trim((string)($data['last_error'] ?? '')), 0, 2000, 'UTF-8');
    $payload = $data['payload'] ?? [];
    if (!is_array($payload)) $payload = [];

    if ($botId <= 0 || $subscriberId <= 0 || $scenarioId <= 0 || $blockId <= 0 || $counterId === '' || $target === '') {
        return ['ok' => false, 'id' => 0, 'status' => 'failed', 'error' => 'Недостаточно данных для постановки события Яндекс.Метрики в очередь.'];
    }

    $telegramUserId = null;
    try {
        $stmt = $pdo->prepare('SELECT telegram_user_id FROM oca_telegram_bot_subscribers WHERE id = ? LIMIT 1');
        $stmt->execute([$subscriberId]);
        $rawUserId = $stmt->fetchColumn();
        if ($rawUserId !== false && $rawUserId !== null && $rawUserId !== '') $telegramUserId = (int)$rawUserId;
    } catch (Throwable $ignored) {}

    if ($clientId === '') {
        $status = 'skipped';
        if ($lastError === '') $lastError = 'У подписчика не заполнен Yandex Client ID.';
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) $payloadJson = '{}';

    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_yandex_metrika_events
        (bot_id, subscriber_id, telegram_user_id, scenario_id, block_id, action_index, counter_id, target, client_id, conversion_at, status, last_error, payload_json, queued_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $botId,
        $subscriberId,
        $telegramUserId,
        $scenarioId,
        $blockId,
        $actionIndex,
        $counterId,
        $target,
        $clientId !== '' ? $clientId : null,
        $status,
        $lastError !== '' ? $lastError : null,
        $payloadJson,
        $status === 'pending' ? date('Y-m-d H:i:s') : null,
    ]);
    $id = (int)$pdo->lastInsertId();
    return ['ok' => true, 'id' => $id, 'status' => $status, 'error' => $lastError, 'client_id' => $clientId, 'counter_id' => $counterId, 'target' => $target];
}

function asr_tg_scenario_action_type_labels(): array {
    return [
        'add_tag' => 'Добавить тег',
        'remove_tag' => 'Удалить тег',
        'set_field' => 'Изменить поле / переменную',
        'stop_scenario' => 'Остановить сценарий',
        'notify_staff' => 'Отправить уведомление',
        'webhook_subscriber' => 'Отправить данные подписчика через Webhook',
        'unsubscribe_bot' => 'Отписать от бота',
        'delete_step_message' => 'Удалить шаг-сообщение',
        'external_request' => 'Внешний запрос',
        'yandex_metrika' => 'Передать данные о событии в Яндекс.Метрику',
    ];
}

function asr_tg_scenario_action_summary(array $action): string {
    $type = (string)($action['type'] ?? '');
    $title = (string)($action['title'] ?? 'Действие');
    if ($type === 'add_tag') {
        $tag = trim((string)($action['tag_name'] ?? ''));
        return 'Добавить тег' . ($tag !== '' ? ': ' . $tag : '');
    }
    if ($type === 'remove_tag') {
        $tag = trim((string)($action['tag_name'] ?? ''));
        return 'Удалить тег' . ($tag !== '' ? ': ' . $tag : '');
    }
    if ($type === 'set_field') {
        $field = trim((string)($action['field_title'] ?? 'поле'));
        $operation = (string)($action['operation'] ?? 'set');
        $operationTitle = ['set' => 'установить', 'clear' => 'очистить', 'inc' => 'прибавить', 'dec' => 'вычесть'][$operation] ?? 'изменить';
        return 'Поле «' . $field . '»: ' . $operationTitle;
    }
    if ($type === 'stop_scenario') return 'Остановить сценарий';
    if ($type === 'notify_staff') return 'Отправить уведомление';
    if ($type === 'webhook_subscriber') return 'Webhook: данные подписчика';
    if ($type === 'unsubscribe_bot') return 'Отписать от бота';
    if ($type === 'delete_step_message') {
        $steps = $action['step_labels'] ?? [];
        if (is_array($steps) && $steps) {
            $first = array_slice(array_values(array_filter(array_map('strval', $steps))), 0, 2);
            $extra = count($steps) - count($first);
            return 'Удалить шаг-сообщение: ' . implode(', ', $first) . ($extra > 0 ? ' +' . $extra : '');
        }
        return 'Удалить шаг-сообщение';
    }
    if ($type === 'external_request') {
        $method = strtoupper(trim((string)($action['method'] ?? 'GET')));
        $url = trim((string)($action['url'] ?? ''));
        $maps = isset($action['mappings']) && is_array($action['mappings']) ? count($action['mappings']) : 0;
        return 'Внешний запрос' . ($url !== '' ? ': ' . $method . ' ' . $url : '') . ($maps > 0 ? ' → ' . $maps . ' сопост.' : '');
    }
    if ($type === 'yandex_metrika') {
        $target = trim((string)($action['target'] ?? ''));
        $counter = trim((string)($action['counter_id'] ?? ''));
        return 'Яндекс.Метрика' . ($target !== '' ? ': ' . $target : '') . ($counter !== '' ? ' · счётчик ' . $counter : '');
    }
    return $title;
}

function asr_tg_scenario_normalize_action_settings(PDO $pdo, array $data): array {
    $rawJson = trim((string)($data['action_cards_json'] ?? $data['actions_json'] ?? ''));
    $rawActions = [];
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) $rawActions = array_values($decoded);
    }
    if (!$rawActions && isset($data['scenario_actions']) && is_array($data['scenario_actions'])) {
        $rawActions = array_values($data['scenario_actions']);
    }

    $labels = asr_tg_scenario_action_type_labels();
    $tags = function_exists('asr_tg_tags_all_light') ? asr_tg_tags_all_light($pdo, 0) : [];
    $tagTitles = [];
    foreach ($tags as $tag) {
        $id = (int)($tag['id'] ?? 0);
        if ($id > 0) $tagTitles[$id] = trim((string)($tag['title'] ?? $tag['name'] ?? ''));
    }
    $fieldCatalog = function_exists('asr_tg_scenario_condition_parameter_catalog') ? asr_tg_scenario_condition_parameter_catalog($pdo) : [];
    $fieldsByKey = [];
    foreach ($fieldCatalog as $field) {
        $key = (string)($field['key'] ?? '');
        if ($key !== '') $fieldsByKey[$key] = $field;
    }

    $out = [];
    $invalidRows = 0;
    foreach ($rawActions as $item) {
        if (!is_array($item)) continue;
        $type = trim((string)($item['type'] ?? ''));
        if (!isset($labels[$type])) { $invalidRows++; continue; }
        $action = ['type' => $type, 'title' => $labels[$type], 'valid' => true];

        if ($type === 'add_tag' || $type === 'remove_tag') {
            $tagId = max(0, (int)($item['tag_id'] ?? $item['value'] ?? 0));
            $action['tag_id'] = $tagId;
            $action['tag_name'] = $tagTitles[$tagId] ?? '';
            if ($tagId <= 0) $action['valid'] = false;
        } elseif ($type === 'set_field') {
            $paramKey = trim((string)($item['param_key'] ?? $item['field_key'] ?? ''));
            $field = $fieldsByKey[$paramKey] ?? [];
            $paramType = (string)($field['param_type'] ?? $item['param_type'] ?? 'text');
            if (!in_array($paramType, ['text','number','date','datetime'], true)) $paramType = 'text';
            $fieldCodeForAction = trim((string)($field['field_code'] ?? ''));
            if (strpos($paramKey, 'field:') === 0 && !in_array($fieldCodeForAction, ['first_name','last_name','phone','email'], true)) {
                $field = [];
            }
            $operation = trim((string)($item['operation'] ?? 'set'));
            if (!in_array($operation, ['set','clear','inc','dec'], true)) $operation = 'set';
            if (!in_array($paramType, ['number'], true) && in_array($operation, ['inc','dec'], true)) $operation = 'set';
            $value = trim((string)($item['value'] ?? ''));
            $action += [
                'param_key' => $paramKey,
                'field_title' => trim((string)($field['title'] ?? $item['field_title'] ?? '')),
                'param_type' => $paramType,
                'operation' => $operation,
                'value' => $value,
            ];
            if ($paramKey === '' || empty($field)) $action['valid'] = false;
            if ($operation !== 'clear' && $value === '') $action['valid'] = false;
        } elseif ($type === 'notify_staff') {
            $staffUserId = max(0, (int)($item['staff_user_id'] ?? 0));
            $message = trim((string)($item['message'] ?? ''));
            $action['staff_user_id'] = $staffUserId;
            $action['message'] = mb_substr($message, 0, 4000, 'UTF-8');
            $action['add_dialog_link'] = !empty($item['add_dialog_link']);
            if ($staffUserId <= 0 || $message === '') $action['valid'] = false;
        } elseif ($type === 'webhook_subscriber') {
            $url = trim((string)($item['url'] ?? ''));
            $action['url'] = mb_substr($url, 0, 500, 'UTF-8');
            $action['include_custom_fields'] = array_key_exists('include_custom_fields', $item) ? !empty($item['include_custom_fields']) : true;
            $action['include_tags'] = array_key_exists('include_tags', $item) ? !empty($item['include_tags']) : true;
            if ($url === '' || !preg_match('~^https?://~i', $url)) $action['valid'] = false;
        } elseif ($type === 'delete_step_message') {
            $rawIds = $item['block_ids'] ?? $item['step_ids'] ?? $item['block_id'] ?? [];
            if (!is_array($rawIds)) $rawIds = [$rawIds];
            $blockIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));
            $labels = asr_tg_scenario_message_block_labels($pdo, (int)($data['scenario_id'] ?? 0), $blockIds);
            $validIds = array_values(array_map('intval', array_keys($labels)));
            $action['block_ids'] = $validIds;
            $action['step_labels'] = array_values($labels);
            if (!$validIds) $action['valid'] = false;
        } elseif ($type === 'external_request') {
            $method = strtoupper(trim((string)($item['method'] ?? 'GET')));
            if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE'], true)) $method = 'GET';
            $url = trim((string)($item['url'] ?? ''));
            $headersRaw = $item['headers'] ?? [];
            if (!is_array($headersRaw)) $headersRaw = [];
            $headers = [];
            foreach ($headersRaw as $header) {
                if (!is_array($header)) continue;
                $key = mb_substr(trim((string)($header['key'] ?? '')), 0, 120, 'UTF-8');
                $value = mb_substr(trim((string)($header['value'] ?? '')), 0, 500, 'UTF-8');
                if ($key === '' && $value === '') continue;
                $headers[] = ['key' => $key, 'value' => $value];
                if (count($headers) >= 20) break;
            }
            $mappingsRaw = $item['mappings'] ?? $item['response_mappings'] ?? [];
            if (!is_array($mappingsRaw)) $mappingsRaw = [];
            $mappings = [];
            foreach ($mappingsRaw as $mapping) {
                if (!is_array($mapping)) continue;
                $path = mb_substr(trim((string)($mapping['response_path'] ?? $mapping['path'] ?? '')), 0, 240, 'UTF-8');
                $target = trim((string)($mapping['target_param_key'] ?? $mapping['param_key'] ?? ''));
                if ($path === '' && $target === '') continue;
                $field = $fieldsByKey[$target] ?? [];
                if ($path === '' || $target === '' || empty($field)) {
                    $action['valid'] = false;
                    continue;
                }
                $paramType = (string)($field['param_type'] ?? 'text');
                if (!in_array($paramType, ['text','number','date','datetime'], true)) $paramType = 'text';
                $mappings[] = [
                    'response_path' => $path,
                    'target_param_key' => $target,
                    'target_title' => trim((string)($field['title'] ?? $target)),
                    'target_type' => $paramType,
                ];
                if (count($mappings) >= 20) break;
            }
            $action['method'] = $method;
            $action['url'] = mb_substr($url, 0, 700, 'UTF-8');
            $action['headers'] = $headers;
            $action['body'] = mb_substr((string)($item['body'] ?? ''), 0, 20000, 'UTF-8');
            $action['mappings'] = $mappings;
            if ($url === '' || !preg_match('~^https://~i', $url)) $action['valid'] = false;
        } elseif ($type === 'yandex_metrika') {
            $counterId = mb_substr(trim((string)($item['counter_id'] ?? '')), 0, 40, 'UTF-8');
            $token = mb_substr(trim((string)($item['token'] ?? '')), 0, 1000, 'UTF-8');
            $target = mb_substr(trim((string)($item['target'] ?? '')), 0, 120, 'UTF-8');
            $clientParamKey = trim((string)($item['client_id_param_key'] ?? $item['client_field'] ?? ''));
            $clientField = $fieldsByKey[$clientParamKey] ?? [];
            if (empty($clientField) || (string)($clientField['source'] ?? '') !== 'custom' || (string)($clientField['param_type'] ?? 'text') !== 'text') {
                $clientField = [];
            }
            $action['counter_id'] = $counterId;
            $action['token'] = $token;
            $action['target'] = $target;
            $action['client_id_param_key'] = $clientParamKey;
            $action['client_id_field_title'] = trim((string)($clientField['title'] ?? ''));
            $action['queue_enabled'] = true;
            if ($counterId === '' || $token === '' || $target === '' || $clientParamKey === '' || empty($clientField)) $action['valid'] = false;
        } elseif ($type === 'stop_scenario') {
            $action['valid'] = true;
        } elseif ($type === 'unsubscribe_bot') {
            $action['valid'] = true;
        }
        $action['summary'] = asr_tg_scenario_action_summary($action);
        if (empty($action['valid'])) $invalidRows++;
        $out[] = $action;
        if (count($out) >= 30) break;
    }

    return [
        'version' => 1,
        'actions' => $out,
        'actions_valid' => count($out) > 0 && $invalidRows === 0,
        'invalid_rows' => $invalidRows,
        'runtime_plan' => ['enabled' => true, 'prepared_for' => 'actions_runner', 'enabled_actions' => ['add_tag','remove_tag','set_field','stop_scenario','notify_staff','webhook_subscriber','unsubscribe_bot','external_request','yandex_metrika']],
    ];
}

function asr_tg_scenario_actions_block_save(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($blockId > 0 && !asr_tg_scenario_block_find($pdo, $blockId, $scenarioId)) throw new InvalidArgumentException('Блок сценария не найден.');
    $title = trim((string)($data['block_title'] ?? ''));
    if ($title === '') $title = 'Действия';
    $settings = asr_tg_scenario_normalize_action_settings($pdo, $data);
    $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить данные блока действий.');
    if ($blockId > 0) {
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_scenario_blocks SET type = 'actions', title = ?, settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?");
        $stmt->execute([$title, $settingsJson, $blockId, $scenarioId]);
        return $blockId;
    }
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start'");
    $stmt->execute([$scenarioId]);
    $blockCount = (int)($stmt->fetchColumn() ?: 0);
    $positionX = 430 + (($blockCount % 4) * 330);
    $positionY = 140 + ((int)floor($blockCount / 4) * 250);
    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'actions', ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$scenarioId, $title, $settingsJson, $positionX, $positionY, $sortOrder]);
    $blockId = (int)$pdo->lastInsertId();
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}

function asr_tg_scenario_normalize_delay_settings(array $data): array {
    $mode = (string)($data['delay_mode'] ?? $data['mode'] ?? 'after');
    if (!in_array($mode, ['after', 'tomorrow', 'at'], true)) $mode = 'after';

    $value = max(1, min(999, (int)($data['delay_value'] ?? $data['value'] ?? 1)));
    $unit = (string)($data['delay_unit'] ?? $data['unit'] ?? 'days');
    if (!in_array($unit, ['seconds', 'minutes', 'hours', 'days'], true)) $unit = 'days';

    $timeMode = (string)($data['send_time_mode'] ?? 'any');
    if (!in_array($timeMode, ['any', 'exact', 'interval'], true)) $timeMode = 'any';

    $normalizeTime = static function($value, string $fallback = '00:00'): string {
        $time = trim((string)$value);
        if (!preg_match('~^(?:[01]?\d|2[0-3]):[0-5]\d$~', $time)) return $fallback;
        [$h, $m] = array_map('intval', explode(':', $time));
        return sprintf('%02d:%02d', $h, $m);
    };

    $timezone = trim((string)($data['timezone'] ?? 'Asia/Almaty')) ?: 'Asia/Almaty';
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = 'Asia/Almaty'; }

    $allowedWeekdays = ['mon','tue','wed','thu','fri','sat','sun'];
    $weekdays = $data['weekdays'] ?? $allowedWeekdays;
    if (!is_array($weekdays)) $weekdays = [];
    $weekdays = array_values(array_intersect($allowedWeekdays, array_map('strval', $weekdays)));
    if (!$weekdays) $weekdays = $allowedWeekdays;

    return [
        'version' => 2,
        'delay_mode' => $mode,
        'delay_value' => $value,
        'delay_unit' => $unit,
        'send_time_mode' => $timeMode,
        'send_time_exact' => $normalizeTime($data['send_time_exact'] ?? '00:00'),
        'send_time_from' => $normalizeTime($data['send_time_from'] ?? '00:00'),
        'send_time_to' => $normalizeTime($data['send_time_to'] ?? '00:00'),
        'timezone' => $timezone,
        'weekdays' => $weekdays,
        'runtime_plan' => [
            'enabled' => true,
            'prepared_for' => 'delay_runner',
        ],
    ];
}

function asr_tg_scenario_delay_block_save(PDO $pdo, array $data): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) {
        throw new InvalidArgumentException('Сценарий не найден.');
    }
    if ($blockId > 0 && !asr_tg_scenario_block_find($pdo, $blockId, $scenarioId)) {
        throw new InvalidArgumentException('Блок сценария не найден.');
    }

    $title = trim((string)($data['block_title'] ?? ''));
    if ($title === '') $title = 'Задержка';
    if (trim((string)($data['timezone'] ?? '')) === '') {
        $data['timezone'] = asr_tg_scenario_timezone($pdo, $scenarioId);
    }
    $settings = asr_tg_scenario_normalize_delay_settings($data);
    $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить данные задержки.');

    if ($blockId > 0) {
        $stmt = $pdo->prepare("UPDATE oca_telegram_bot_scenario_blocks SET type = 'delay', title = ?, settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?");
        $stmt->execute([$title, $settingsJson, $blockId, $scenarioId]);
        asr_tg_scenario_sync_block_links($pdo, $scenarioId, $blockId, []);
        return $blockId;
    }

    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start'");
    $stmt->execute([$scenarioId]);
    $blockCount = (int)($stmt->fetchColumn() ?: 0);
    $positionX = 430 + (($blockCount % 4) * 330);
    $positionY = 140 + ((int)floor($blockCount / 4) * 250);
    $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'delay', ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$scenarioId, $title, $settingsJson, $positionX, $positionY, $sortOrder]);
    $blockId = (int)$pdo->lastInsertId();
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}

function asr_tg_scenario_block_save(PDO $pdo, array $data, array $files = []): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($data['scenario_id'] ?? 0));
    $blockId = max(0, (int)($data['block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) {
        throw new InvalidArgumentException('Сценарий не найден.');
    }
    if ($blockId > 0 && !asr_tg_scenario_block_find($pdo, $blockId, $scenarioId)) {
        throw new InvalidArgumentException('Блок сценария не найден.');
    }

    $type = (string)($data['block_type'] ?? 'message');
    if ($type === 'delay') {
        return asr_tg_scenario_delay_block_save($pdo, $data);
    }
    if ($type === 'condition') {
        return asr_tg_scenario_condition_block_save($pdo, $data);
    }
    if ($type === 'actions') {
        return asr_tg_scenario_actions_block_save($pdo, $data);
    }
    // Стартовый блок создаётся системой отдельно. Через эту форму сохраняются только сообщения.
    if ($type !== 'message') $type = 'message';
    $title = trim((string)($data['block_title'] ?? ''));
    if ($title === '') $title = 'Сообщение';

    $cards = asr_tg_scenario_normalize_block_cards((string)($data['scenario_cards_json'] ?? ''));
    $cards = asr_tg_scenario_apply_card_uploads($cards, $files);
    if (!$cards) {
        throw new InvalidArgumentException('Заполните хотя бы одну карточку сообщения.');
    }
    $settingsJson = json_encode(['version' => 2, 'cards' => $cards], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить данные блока.');

    if ($blockId > 0) {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET type = ?, title = ?, settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
        $stmt->execute([$type, $title, $settingsJson, $blockId, $scenarioId]);
        asr_tg_scenario_sync_block_links($pdo, $scenarioId, $blockId, $cards);
        return $blockId;
    }

    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND type <> 'start'");
    $stmt->execute([$scenarioId]);
    $messageCount = (int)($stmt->fetchColumn() ?: 0);
    $positionX = 430 + (($messageCount % 4) * 330);
    $positionY = 140 + ((int)floor($messageCount / 4) * 250);
    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$scenarioId, $type, $title, $settingsJson, $positionX, $positionY, $sortOrder]);
    $blockId = (int)$pdo->lastInsertId();
    asr_tg_scenario_sync_block_links($pdo, $scenarioId, $blockId, $cards);
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}

function asr_tg_scenario_block_save_from_post(PDO $pdo, array $post, array $files = []): int {
    return asr_tg_scenario_block_save($pdo, $post, $files);
}

function asr_tg_scenario_sync_block_links(PDO $pdo, int $scenarioId, int $blockId, array $cards): void {
    if ($scenarioId <= 0 || $blockId <= 0) return;
    $pdo->prepare("DELETE FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type IN ('button','question_answer','question_no_answer')")->execute([$scenarioId, $blockId]);

    $targetStmt = $pdo->prepare('SELECT id FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND id = ? LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_links (scenario_id, from_block_id, to_block_id, link_type, condition_json, button_text, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, NOW(), NOW())');
    $sortOrder = 100;
    foreach ($cards as $card) {
        if (!is_array($card)) continue;
        if ((string)($card['type'] ?? '') === 'question') {
            $answers = isset($card['answers']) && is_array($card['answers']) ? array_values($card['answers']) : [];
            foreach ($answers as $answer) {
                if (!is_array($answer)) continue;
                $targetBlockId = max(0, (int)($answer['target_block_id'] ?? 0));
                if ($targetBlockId <= 0 || $targetBlockId === $blockId) continue;
                $targetStmt->execute([$scenarioId, $targetBlockId]);
                if (!$targetStmt->fetchColumn()) continue;
                $title = trim((string)($answer['text'] ?? '')) ?: 'Ответ';
                $insertStmt->execute([$scenarioId, $blockId, $targetBlockId, 'question_answer', mb_substr($title, 0, 190, 'UTF-8'), $sortOrder]);
                $sortOrder += 100;
            }
            $noAnswerTarget = max(0, (int)($card['no_answer_target_block_id'] ?? 0));
            if ($noAnswerTarget > 0 && $noAnswerTarget !== $blockId) {
                $targetStmt->execute([$scenarioId, $noAnswerTarget]);
                if ($targetStmt->fetchColumn()) {
                    $insertStmt->execute([$scenarioId, $blockId, $noAnswerTarget, 'question_no_answer', 'Подписчик не ответил', $sortOrder]);
                    $sortOrder += 100;
                }
            }
            continue;
        }
        $rows = $card['buttons'] ?? [];
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $btn) {
                if (!is_array($btn)) continue;
                if ((string)($btn['type'] ?? 'url') !== 'transition') continue;
                $targetBlockId = max(0, (int)($btn['target_block_id'] ?? 0));
                if ($targetBlockId <= 0 || $targetBlockId === $blockId) continue;
                $targetStmt->execute([$scenarioId, $targetBlockId]);
                if (!$targetStmt->fetchColumn()) continue;
                $title = trim((string)($btn['text'] ?? '')) ?: 'Переход';
                $insertStmt->execute([$scenarioId, $blockId, $targetBlockId, 'button', mb_substr($title, 0, 190, 'UTF-8'), $sortOrder]);
                $sortOrder += 100;
            }
        }
    }
}


function asr_tg_scenario_condition_link_save(PDO $pdo, int $scenarioId, int $fromBlockId, int $toBlockId, string $sourceHandle): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $fromBlockId <= 0 || $toBlockId <= 0) throw new InvalidArgumentException('Не выбраны блоки для связи.');
    if ($fromBlockId === $toBlockId) throw new InvalidArgumentException('Блок нельзя соединить с самим собой.');
    $from = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    $to = asr_tg_scenario_block_find($pdo, $toBlockId, $scenarioId);
    if (!$from || !$to) throw new InvalidArgumentException('Один из блоков не найден.');
    if ((string)($from['type'] ?? '') !== 'condition') throw new InvalidArgumentException('Исходный блок не является условием.');
    if ((string)($to['type'] ?? '') === 'start') throw new InvalidArgumentException('Нельзя вести переход в стартовый блок.');
    $linkType = $sourceHandle === 'condition-no' ? 'condition_no' : 'condition_yes';
    $buttonText = $linkType === 'condition_no' ? 'Нет' : 'Да';
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type = ?")->execute([$scenarioId, $fromBlockId, $linkType]);
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_links (scenario_id, from_block_id, to_block_id, link_type, condition_json, button_text, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, NOW(), NOW())');
        $stmt->execute([$scenarioId, $fromBlockId, $toBlockId, $linkType, $buttonText, $linkType === 'condition_yes' ? 60 : 70]);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_link_save(PDO $pdo, int $scenarioId, int $fromBlockId, int $toBlockId): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $fromBlockId <= 0 || $toBlockId <= 0) throw new InvalidArgumentException('Не выбраны блоки для связи.');
    if ($fromBlockId === $toBlockId) throw new InvalidArgumentException('Блок нельзя соединить с самим собой.');
    $from = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    $to = asr_tg_scenario_block_find($pdo, $toBlockId, $scenarioId);
    if (!$from || !$to) throw new InvalidArgumentException('Один из блоков не найден.');
    if ((string)($to['type'] ?? '') === 'start') throw new InvalidArgumentException('Нельзя вести переход в стартовый блок.');

    $linkType = ((string)($from['type'] ?? '') === 'start') ? 'start' : 'manual';
    $buttonText = $linkType === 'start' ? 'Начать' : 'Следующий шаг';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND from_block_id = ? AND link_type IN ('start','manual')")->execute([$scenarioId, $fromBlockId]);
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_links (scenario_id, from_block_id, to_block_id, link_type, condition_json, button_text, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, 50, NOW(), NOW())');
        $stmt->execute([$scenarioId, $fromBlockId, $toBlockId, $linkType, $buttonText]);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


function asr_tg_scenario_button_link_save(PDO $pdo, int $scenarioId, int $fromBlockId, int $toBlockId, string $sourceHandle): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $fromBlockId <= 0 || $toBlockId <= 0) throw new InvalidArgumentException('Не выбраны блоки для связи.');
    if ($fromBlockId === $toBlockId) throw new InvalidArgumentException('Блок нельзя соединить с самим собой.');
    if (!preg_match('/^btn-c(\d+)-r(\d+)-b(\d+)$/', $sourceHandle, $m)) throw new InvalidArgumentException('Не найдена кнопка для связи.');
    $cardIndex = (int)$m[1];
    $rowIndex = (int)$m[2];
    $buttonIndex = (int)$m[3];

    $from = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    $to = asr_tg_scenario_block_find($pdo, $toBlockId, $scenarioId);
    if (!$from || !$to) throw new InvalidArgumentException('Один из блоков не найден.');
    if ((string)($to['type'] ?? '') === 'start') throw new InvalidArgumentException('Нельзя вести переход в стартовый блок.');
    if ((string)($from['type'] ?? '') === 'start') throw new InvalidArgumentException('У стартового блока нет кнопок для перехода.');

    $settings = json_decode((string)($from['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $cards = isset($settings['cards']) && is_array($settings['cards']) ? array_values($settings['cards']) : [];
    if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex])) throw new InvalidArgumentException('Карточка кнопки не найдена.');
    if (!isset($cards[$cardIndex]['buttons']) || !is_array($cards[$cardIndex]['buttons'])) throw new InvalidArgumentException('Кнопка не найдена.');
    $rows = array_values($cards[$cardIndex]['buttons']);
    if (!isset($rows[$rowIndex])) throw new InvalidArgumentException('Строка кнопки не найдена.');
    $row = is_array($rows[$rowIndex]) && array_keys($rows[$rowIndex]) === range(0, count($rows[$rowIndex]) - 1) ? array_values($rows[$rowIndex]) : [$rows[$rowIndex]];
    if (!isset($row[$buttonIndex]) || !is_array($row[$buttonIndex])) throw new InvalidArgumentException('Кнопка не найдена.');

    $row[$buttonIndex]['type'] = 'transition';
    $row[$buttonIndex]['url'] = '';
    $row[$buttonIndex]['target_block_id'] = $toBlockId;
    $row[$buttonIndex]['text'] = trim((string)($row[$buttonIndex]['text'] ?? '')) ?: 'Переход';
    $rows[$rowIndex] = $row;
    $cards[$cardIndex]['buttons'] = $rows;
    $settings['cards'] = asr_tg_scenario_normalize_block_cards($cards);
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Не удалось сохранить переход кнопки.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
        $stmt->execute([$encoded, $fromBlockId, $scenarioId]);
        asr_tg_scenario_sync_block_links($pdo, $scenarioId, $fromBlockId, $settings['cards']);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


function asr_tg_scenario_question_answer_link_save(PDO $pdo, int $scenarioId, int $fromBlockId, int $toBlockId, string $sourceHandle): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $fromBlockId <= 0 || $toBlockId <= 0) throw new InvalidArgumentException('Не выбраны блоки для связи.');
    if ($fromBlockId === $toBlockId) throw new InvalidArgumentException('Блок нельзя соединить с самим собой.');
    if (!preg_match('/^q-a(\d+)-(\d+)$/', $sourceHandle, $m)) throw new InvalidArgumentException('Не найден ответ для связи.');
    $cardIndex = (int)$m[1];
    $answerIndex = (int)$m[2];
    $from = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    $to = asr_tg_scenario_block_find($pdo, $toBlockId, $scenarioId);
    if (!$from || !$to) throw new InvalidArgumentException('Один из блоков не найден.');
    if ((string)($to['type'] ?? '') === 'start') throw new InvalidArgumentException('Нельзя вести переход в стартовый блок.');
    if ((string)($from['type'] ?? '') === 'start') throw new InvalidArgumentException('У стартового блока нет ответов для перехода.');
    $settings = json_decode((string)($from['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $cards = isset($settings['cards']) && is_array($settings['cards']) ? array_values($settings['cards']) : [];
    if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex]) || (string)($cards[$cardIndex]['type'] ?? '') !== 'question') throw new InvalidArgumentException('Карточка вопроса не найдена.');
    $answers = isset($cards[$cardIndex]['answers']) && is_array($cards[$cardIndex]['answers']) ? array_values($cards[$cardIndex]['answers']) : [];
    if (!isset($answers[$answerIndex]) || !is_array($answers[$answerIndex])) throw new InvalidArgumentException('Ответ не найден.');
    $answers[$answerIndex]['target_block_id'] = $toBlockId;
    $answers[$answerIndex]['text'] = trim((string)($answers[$answerIndex]['text'] ?? $answers[$answerIndex]['title'] ?? '')) ?: 'Ответ';
    $cards[$cardIndex]['answers'] = $answers;
    $settings['cards'] = asr_tg_scenario_normalize_block_cards($cards);
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Не удалось сохранить переход ответа.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
        $stmt->execute([$encoded, $fromBlockId, $scenarioId]);
        asr_tg_scenario_sync_block_links($pdo, $scenarioId, $fromBlockId, $settings['cards']);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_question_noanswer_link_save(PDO $pdo, int $scenarioId, int $fromBlockId, int $toBlockId, string $sourceHandle): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $fromBlockId <= 0 || $toBlockId <= 0) throw new InvalidArgumentException('Не выбраны блоки для связи.');
    if ($fromBlockId === $toBlockId) throw new InvalidArgumentException('Блок нельзя соединить с самим собой.');
    if (!preg_match('/^q-noanswer-c(\d+)$/', $sourceHandle, $m)) throw new InvalidArgumentException('Не найден выход «Подписчик не ответил».');
    $cardIndex = (int)$m[1];
    $from = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    $to = asr_tg_scenario_block_find($pdo, $toBlockId, $scenarioId);
    if (!$from || !$to) throw new InvalidArgumentException('Один из блоков не найден.');
    if ((string)($to['type'] ?? '') === 'start') throw new InvalidArgumentException('Нельзя вести переход в стартовый блок.');
    if ((string)($from['type'] ?? '') === 'start') throw new InvalidArgumentException('У стартового блока нет выхода «Подписчик не ответил».');
    $settings = json_decode((string)($from['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $cards = isset($settings['cards']) && is_array($settings['cards']) ? array_values($settings['cards']) : [];
    if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex]) || (string)($cards[$cardIndex]['type'] ?? '') !== 'question') throw new InvalidArgumentException('Карточка вопроса не найдена.');
    $cards[$cardIndex]['no_answer_target_block_id'] = $toBlockId;
    $settings['cards'] = asr_tg_scenario_normalize_block_cards($cards);
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Не удалось сохранить выход «Подписчик не ответил».');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
        $stmt->execute([$encoded, $fromBlockId, $scenarioId]);
        asr_tg_scenario_sync_block_links($pdo, $scenarioId, $fromBlockId, $settings['cards']);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_link_save_from_post(PDO $pdo, array $post): void {
    $sourceHandle = trim((string)($post['source_handle'] ?? 'out'));
    if (strpos($sourceHandle, 'btn-') === 0) {
        asr_tg_scenario_button_link_save($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['from_block_id'] ?? 0), (int)($post['to_block_id'] ?? 0), $sourceHandle);
        return;
    }
    if (preg_match('/^q-a\d+-\d+$/', $sourceHandle)) {
        asr_tg_scenario_question_answer_link_save($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['from_block_id'] ?? 0), (int)($post['to_block_id'] ?? 0), $sourceHandle);
        return;
    }
    if (preg_match('/^q-noanswer-c\d+$/', $sourceHandle)) {
        asr_tg_scenario_question_noanswer_link_save($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['from_block_id'] ?? 0), (int)($post['to_block_id'] ?? 0), $sourceHandle);
        return;
    }
    if (in_array($sourceHandle, ['condition-yes','condition-no'], true)) {
        asr_tg_scenario_condition_link_save($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['from_block_id'] ?? 0), (int)($post['to_block_id'] ?? 0), $sourceHandle);
        return;
    }
    asr_tg_scenario_link_save($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['from_block_id'] ?? 0), (int)($post['to_block_id'] ?? 0));
}

function asr_tg_scenario_link_clear_button_target(PDO $pdo, int $scenarioId, int $fromBlockId, string $sourceHandle): void {
    if (!preg_match('/^btn-c(\d+)-r(\d+)-b(\d+)$/', $sourceHandle, $m)) return;
    $cardIndex = (int)$m[1];
    $rowIndex = (int)$m[2];
    $buttonIndex = (int)$m[3];
    $block = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    if (!$block) return;
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $cards = isset($settings['cards']) && is_array($settings['cards']) ? array_values($settings['cards']) : [];
    if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex])) return;
    $rows = isset($cards[$cardIndex]['buttons']) && is_array($cards[$cardIndex]['buttons']) ? array_values($cards[$cardIndex]['buttons']) : [];
    if (!isset($rows[$rowIndex])) return;
    $row = is_array($rows[$rowIndex]) && array_keys($rows[$rowIndex]) === range(0, count($rows[$rowIndex]) - 1) ? array_values($rows[$rowIndex]) : [$rows[$rowIndex]];
    if (!isset($row[$buttonIndex]) || !is_array($row[$buttonIndex])) return;
    if ((string)($row[$buttonIndex]['type'] ?? '') !== 'transition' && empty($row[$buttonIndex]['target_block_id'])) return;
    $row[$buttonIndex]['type'] = 'url';
    $row[$buttonIndex]['target_block_id'] = 0;
    $row[$buttonIndex]['url'] = '';
    $rows[$rowIndex] = $row;
    $cards[$cardIndex]['buttons'] = $rows;
    $settings['cards'] = asr_tg_scenario_normalize_block_cards($cards);
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Не удалось обновить кнопку после удаления связи.');
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
    $stmt->execute([$encoded, $fromBlockId, $scenarioId]);
}


function asr_tg_scenario_link_clear_question_target(PDO $pdo, int $scenarioId, int $fromBlockId, string $sourceHandle): void {
    if (!preg_match('/^q-a(\d+)-(\d+)$/', $sourceHandle, $answerMatch) && !preg_match('/^q-noanswer-c(\d+)$/', $sourceHandle, $noAnswerMatch)) return;
    $block = asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId);
    if (!$block) return;
    $settings = json_decode((string)($block['settings_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $cards = isset($settings['cards']) && is_array($settings['cards']) ? array_values($settings['cards']) : [];
    if (!empty($answerMatch)) {
        $cardIndex = (int)$answerMatch[1];
        $answerIndex = (int)$answerMatch[2];
        if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex]) || (string)($cards[$cardIndex]['type'] ?? '') !== 'question') return;
        $answers = isset($cards[$cardIndex]['answers']) && is_array($cards[$cardIndex]['answers']) ? array_values($cards[$cardIndex]['answers']) : [];
        if (!isset($answers[$answerIndex]) || !is_array($answers[$answerIndex])) return;
        $answers[$answerIndex]['target_block_id'] = 0;
        $cards[$cardIndex]['answers'] = $answers;
    } else {
        $cardIndex = (int)$noAnswerMatch[1];
        if (!isset($cards[$cardIndex]) || !is_array($cards[$cardIndex]) || (string)($cards[$cardIndex]['type'] ?? '') !== 'question') return;
        $cards[$cardIndex]['no_answer_target_block_id'] = 0;
    }
    $settings['cards'] = asr_tg_scenario_normalize_block_cards($cards);
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) throw new RuntimeException('Не удалось обновить карточку вопроса после удаления связи.');
    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET settings_json = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
    $stmt->execute([$encoded, $fromBlockId, $scenarioId]);
}

function asr_tg_scenario_link_delete(PDO $pdo, int $scenarioId, int $linkId, string $sourceHandle = ''): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $linkId <= 0) throw new InvalidArgumentException('Не выбрана связь для удаления.');
    $stmt = $pdo->prepare('SELECT * FROM oca_telegram_bot_scenario_links WHERE id = ? AND scenario_id = ? LIMIT 1');
    $stmt->execute([$linkId, $scenarioId]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) throw new InvalidArgumentException('Связь не найдена.');
    $fromBlockId = (int)($link['from_block_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_links WHERE id = ? AND scenario_id = ?');
        $del->execute([$linkId, $scenarioId]);
        if ($sourceHandle !== '' && strpos($sourceHandle, 'btn-') === 0 && $fromBlockId > 0) {
            asr_tg_scenario_link_clear_button_target($pdo, $scenarioId, $fromBlockId, $sourceHandle);
        } elseif ($sourceHandle !== '' && $fromBlockId > 0 && (preg_match('/^q-a\d+-\d+$/', $sourceHandle) || preg_match('/^q-noanswer-c\d+$/', $sourceHandle))) {
            asr_tg_scenario_link_clear_question_target($pdo, $scenarioId, $fromBlockId, $sourceHandle);
        }
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_link_delete_from_post(PDO $pdo, array $post): void {
    $edgeId = trim((string)($post['edge_id'] ?? ''));
    $linkId = (int)($post['link_id'] ?? 0);
    if ($linkId <= 0 && preg_match('/^link-(\d+)$/', $edgeId, $m)) $linkId = (int)$m[1];
    asr_tg_scenario_link_delete($pdo, (int)($post['scenario_id'] ?? 0), $linkId, trim((string)($post['source_handle'] ?? '')));
}


function asr_tg_scenario_quick_message_create_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $fromBlockId = max(0, (int)($post['from_block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($fromBlockId > 0 && !asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId)) throw new InvalidArgumentException('Исходный блок не найден.');

    $x = max(-10000, min(20000, (int)($post['position_x'] ?? 430)));
    $y = max(-10000, min(20000, (int)($post['position_y'] ?? 140)));
    $settingsJson = json_encode([
        'version' => 2,
        'cards' => [[
            'type' => 'text',
            'text' => 'Новое сообщение',
            'media_url' => '',
            'buttons' => [],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить новый блок.');

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'message', 'Сообщение', ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$scenarioId, $settingsJson, $x, $y, $sortOrder]);
        $blockId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($fromBlockId > 0) {
        $sourceHandle = trim((string)($post['source_handle'] ?? 'out'));
        if (strpos($sourceHandle, 'btn-') === 0) {
            asr_tg_scenario_button_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-a\d+-\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_answer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-noanswer-c\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_noanswer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (in_array($sourceHandle, ['condition-yes','condition-no'], true)) {
            asr_tg_scenario_condition_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } else {
            asr_tg_scenario_link_save($pdo, $scenarioId, $fromBlockId, $blockId);
        }
    }
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}

function asr_tg_scenario_quick_delay_create_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $fromBlockId = max(0, (int)($post['from_block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($fromBlockId > 0 && !asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId)) throw new InvalidArgumentException('Исходный блок не найден.');

    $x = max(-10000, min(20000, (int)($post['position_x'] ?? 430)));
    $y = max(-10000, min(20000, (int)($post['position_y'] ?? 140)));
    $scenarioTimezone = asr_tg_scenario_timezone($pdo, $scenarioId);
    $settingsJson = json_encode([
        'version' => 2,
        'delay_mode' => 'after',
        'delay_value' => 1,
        'delay_unit' => 'days',
        'send_time_mode' => 'any',
        'send_time_exact' => '00:00',
        'send_time_from' => '00:00',
        'send_time_to' => '00:00',
        'timezone' => $scenarioTimezone,
        'weekdays' => ['mon','tue','wed','thu','fri','sat','sun'],
        'runtime_plan' => [
            'enabled' => false,
            'prepared_for' => 'delay_runner',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить новый блок задержки.');

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'delay', 'Задержка', ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$scenarioId, $settingsJson, $x, $y, $sortOrder]);
        $blockId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($fromBlockId > 0) {
        $sourceHandle = trim((string)($post['source_handle'] ?? 'out'));
        if (strpos($sourceHandle, 'btn-') === 0) {
            asr_tg_scenario_button_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-a\d+-\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_answer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-noanswer-c\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_noanswer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (in_array($sourceHandle, ['condition-yes','condition-no'], true)) {
            asr_tg_scenario_condition_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } else {
            asr_tg_scenario_link_save($pdo, $scenarioId, $fromBlockId, $blockId);
        }
    }
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}


function asr_tg_scenario_quick_condition_create_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $fromBlockId = max(0, (int)($post['from_block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($fromBlockId > 0 && !asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId)) throw new InvalidArgumentException('Исходный блок не найден.');
    $x = max(-10000, min(20000, (int)($post['position_x'] ?? 430)));
    $y = max(-10000, min(20000, (int)($post['position_y'] ?? 140)));
    $settingsJson = json_encode([
        'version' => 1,
        'condition_match_mode' => 'all',
        'conditions' => [],
        'condition_valid' => false,
        'invalid_rows' => 0,
        'max_conditions' => 15,
        'runtime_plan' => ['enabled' => false, 'prepared_for' => 'condition_runner'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить новый блок условия.');
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'condition', 'Условие', ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$scenarioId, $settingsJson, $x, $y, $sortOrder]);
        $blockId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($fromBlockId > 0) {
        $sourceHandle = trim((string)($post['source_handle'] ?? 'out'));
        if (strpos($sourceHandle, 'btn-') === 0) {
            asr_tg_scenario_button_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-a\d+-\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_answer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (preg_match('/^q-noanswer-c\d+$/', $sourceHandle)) {
            asr_tg_scenario_question_noanswer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } elseif (in_array($sourceHandle, ['condition-yes','condition-no'], true)) {
            asr_tg_scenario_condition_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
        } else {
            asr_tg_scenario_link_save($pdo, $scenarioId, $fromBlockId, $blockId);
        }
    }
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}


function asr_tg_scenario_link_from_quick_block(PDO $pdo, int $scenarioId, int $fromBlockId, int $blockId, string $sourceHandle): void {
    if ($fromBlockId <= 0 || $blockId <= 0) return;
    if (strpos($sourceHandle, 'btn-') === 0) {
        asr_tg_scenario_button_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
    } elseif (preg_match('/^q-a\d+-\d+$/', $sourceHandle)) {
        asr_tg_scenario_question_answer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
    } elseif (preg_match('/^q-noanswer-c\d+$/', $sourceHandle)) {
        asr_tg_scenario_question_noanswer_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
    } elseif (in_array($sourceHandle, ['condition-yes','condition-no'], true)) {
        asr_tg_scenario_condition_link_save($pdo, $scenarioId, $fromBlockId, $blockId, $sourceHandle);
    } else {
        asr_tg_scenario_link_save($pdo, $scenarioId, $fromBlockId, $blockId);
    }
}

function asr_tg_scenario_quick_actions_create_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $fromBlockId = max(0, (int)($post['from_block_id'] ?? 0));
    if ($scenarioId <= 0 || !asr_tg_scenario_find($pdo, $scenarioId)) throw new InvalidArgumentException('Сценарий не найден.');
    if ($fromBlockId > 0 && !asr_tg_scenario_block_find($pdo, $fromBlockId, $scenarioId)) throw new InvalidArgumentException('Исходный блок не найден.');
    $x = max(-10000, min(20000, (int)($post['position_x'] ?? 430)));
    $y = max(-10000, min(20000, (int)($post['position_y'] ?? 140)));
    $settingsJson = json_encode([
        'version' => 1,
        'actions' => [],
        'actions_valid' => false,
        'invalid_rows' => 0,
        'runtime_plan' => ['enabled' => false, 'prepared_for' => 'actions_runner'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($settingsJson === false) throw new RuntimeException('Не удалось подготовить новый блок действий.');
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, 'actions', 'Действия', ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$scenarioId, $settingsJson, $x, $y, $sortOrder]);
        $blockId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    asr_tg_scenario_link_from_quick_block($pdo, $scenarioId, $fromBlockId, $blockId, trim((string)($post['source_handle'] ?? 'out')));
    asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    return $blockId;
}

function asr_tg_scenario_block_delete(PDO $pdo, int $scenarioId, int $blockId): void {
    asr_tg_repository_ensure_scenario_schema($pdo);
    if ($scenarioId <= 0 || $blockId <= 0) throw new InvalidArgumentException('Блок не выбран.');
    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block) throw new InvalidArgumentException('Блок сценария не найден.');
    if ((string)($block['type'] ?? '') === 'start') throw new InvalidArgumentException('Стартовый блок нельзя удалить.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_card_buttons WHERE scenario_id = ? AND block_id = ?')->execute([$scenarioId, $blockId]);
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_block_cards WHERE scenario_id = ? AND block_id = ?')->execute([$scenarioId, $blockId]);
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_deeplinks WHERE scenario_id = ? AND block_id = ?')->execute([$scenarioId, $blockId]);
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_links WHERE scenario_id = ? AND (from_block_id = ? OR to_block_id = ?)')->execute([$scenarioId, $blockId, $blockId]);
        $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ? AND id = ?')->execute([$scenarioId, $blockId]);
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
        $pdo->commit();
        asr_tg_scenario_ensure_start_block($pdo, $scenarioId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function asr_tg_scenario_block_delete_from_post(PDO $pdo, array $post): void {
    asr_tg_scenario_block_delete($pdo, (int)($post['scenario_id'] ?? 0), (int)($post['block_id'] ?? 0));
}

function asr_tg_scenario_block_duplicate_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    $blockId = max(0, (int)($post['block_id'] ?? 0));
    if ($scenarioId <= 0 || $blockId <= 0) throw new InvalidArgumentException('Блок не выбран.');
    $block = asr_tg_scenario_block_find($pdo, $blockId, $scenarioId);
    if (!$block) throw new InvalidArgumentException('Блок сценария не найден.');
    if ((string)($block['type'] ?? '') === 'start') throw new InvalidArgumentException('Стартовый блок нельзя дублировать.');

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 100 FROM oca_telegram_bot_scenario_blocks WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
    $sortOrder = (int)($stmt->fetchColumn() ?: 100);

    $title = trim((string)($block['title'] ?? '')) ?: 'Сообщение';
    $copyTitle = mb_strlen($title, 'UTF-8') > 90
        ? mb_substr($title, 0, 90, 'UTF-8') . '… копия'
        : $title . ' копия';
    $x = max(40, min(2600, (int)($block['position_x'] ?? 430) + 56));
    $y = max(80, min(1800, (int)($block['position_y'] ?? 140) + 56));

    $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_blocks (scenario_id, type, title, settings_json, position_x, position_y, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $scenarioId,
        (string)($block['type'] ?? 'message'),
        $copyTitle,
        (string)($block['settings_json'] ?? '{}'),
        $x,
        $y,
        $sortOrder,
    ]);
    $newBlockId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
    return $newBlockId;
}


if (!function_exists('asr_tg_scenario_blocks_positions_save_from_post')) {
function asr_tg_scenario_blocks_positions_save_from_post(PDO $pdo, array $post): int {
    asr_tg_repository_ensure_scenario_schema($pdo);

    $scenarioId = max(0, (int)($post['scenario_id'] ?? 0));
    if ($scenarioId <= 0) {
        throw new InvalidArgumentException('Сценарий не выбран.');
    }
    if (!asr_tg_scenario_find($pdo, $scenarioId)) {
        throw new InvalidArgumentException('Сценарий не найден.');
    }

    $positions = [];
    if (isset($post['positions_json'])) {
        $decoded = json_decode((string)$post['positions_json'], true);
        if (is_array($decoded)) {
            $positions = $decoded;
        }
    }
    if (!$positions && isset($post['positions']) && is_array($post['positions'])) {
        $positions = $post['positions'];
    }

    $stmt = $pdo->prepare('UPDATE oca_telegram_bot_scenario_blocks SET position_x = ?, position_y = ?, updated_at = NOW() WHERE id = ? AND scenario_id = ?');
    $saved = 0;

    foreach ($positions as $blockId => $pos) {
        if (!is_array($pos)) {
            continue;
        }
        $id = (int)$blockId;
        if ($id <= 0) {
            continue;
        }

        $xRaw = is_numeric($pos['x'] ?? null) ? (float)$pos['x'] : 120.0;
        $yRaw = is_numeric($pos['y'] ?? null) ? (float)$pos['y'] : 120.0;

        // React Flow stores canvas coordinates, not visible screen coordinates.
        // Coordinates may be negative or very large after panning; clamping them
        // to the current viewport makes blocks drift after refresh.
        $x = max(-20000, min(20000, (int)round($xRaw)));
        $y = max(-20000, min(20000, (int)round($yRaw)));

        $stmt->execute([$x, $y, $id, $scenarioId]);
        $saved++;
    }

    if ($saved > 0) {
        $pdo->prepare('UPDATE oca_telegram_bot_scenarios SET updated_at = NOW() WHERE id = ?')->execute([$scenarioId]);
    }

    return $saved;
}
}


if (!function_exists('asr_tg_yandex_metrika_queue_stats')) {
function asr_tg_yandex_metrika_queue_stats(PDO $pdo): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $stats = [
        'total' => 0,
        'pending' => 0,
        'retry' => 0,
        'sent' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM oca_telegram_bot_yandex_metrika_events GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['status'] ?? '');
        $cnt = (int)($row['cnt'] ?? 0);
        if ($status !== '') {
            $stats[$status] = $cnt;
        }
        $stats['total'] += $cnt;
    }
    return $stats;
}
}

if (!function_exists('asr_tg_yandex_metrika_queue_recent')) {
function asr_tg_yandex_metrika_queue_recent(PDO $pdo, int $limit = 50): array {
    asr_tg_repository_ensure_scenario_schema($pdo);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT ym.*, b.title AS bot_title, s.title AS scenario_title, bl.title AS block_title
            FROM oca_telegram_bot_yandex_metrika_events ym
            LEFT JOIN oca_telegram_bots b ON b.id = ym.bot_id
            LEFT JOIN oca_telegram_bot_scenarios s ON s.id = ym.scenario_id
            LEFT JOIN oca_telegram_bot_scenario_blocks bl ON bl.id = ym.block_id
            ORDER BY ym.id DESC
            LIMIT " . (int)$limit;
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
}
