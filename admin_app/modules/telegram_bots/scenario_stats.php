<?php
defined('ASR_ADMIN') || exit;

function asr_tg_scenario_stats_ensure_schema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS oca_telegram_bot_scenario_block_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scenario_id INT NOT NULL,
            block_id INT NOT NULL,
            sent_count INT NOT NULL DEFAULT 0,
            click_count INT NOT NULL DEFAULT 0,
            last_sent_at DATETIME NULL,
            last_click_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scenario_block (scenario_id, block_id),
            KEY idx_scenario (scenario_id),
            KEY idx_block (block_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, 0, 'warning', 'scenario_stats_schema_failed', $e->getMessage()); } catch (Throwable $ignored) {}
    }
}

function asr_tg_scenario_stats_for_scenario(PDO $pdo, int $scenarioId): array {
    if ($scenarioId <= 0) return [];
    asr_tg_scenario_stats_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT block_id, sent_count, click_count FROM oca_telegram_bot_scenario_block_stats WHERE scenario_id = ?');
        $stmt->execute([$scenarioId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sent = max(0, (int)($row['sent_count'] ?? 0));
            $clicks = max(0, (int)($row['click_count'] ?? 0));
            $out[(int)($row['block_id'] ?? 0)] = [
                'sent' => $sent,
                'clicks' => $clicks,
                'clickRate' => $sent > 0 ? (int)round(($clicks / $sent) * 100) : 0,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, 0, 'warning', 'scenario_stats_load_failed', $e->getMessage(), ['scenario_id' => $scenarioId]); } catch (Throwable $ignored) {}
        return [];
    }
}

function asr_tg_scenario_stats_increment_sent(PDO $pdo, int $scenarioId, int $blockId): void {
    if ($scenarioId <= 0 || $blockId <= 0) return;
    asr_tg_scenario_stats_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_block_stats (scenario_id, block_id, sent_count, last_sent_at, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE sent_count = sent_count + 1, last_sent_at = NOW(), updated_at = NOW()');
        $stmt->execute([$scenarioId, $blockId]);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, 0, 'warning', 'scenario_stats_sent_increment_failed', $e->getMessage(), ['scenario_id' => $scenarioId, 'block_id' => $blockId]); } catch (Throwable $ignored) {}
    }
}

function asr_tg_scenario_stats_increment_click(PDO $pdo, int $scenarioId, int $blockId): void {
    if ($scenarioId <= 0 || $blockId <= 0) return;
    asr_tg_scenario_stats_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare('INSERT INTO oca_telegram_bot_scenario_block_stats (scenario_id, block_id, click_count, last_click_at, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE click_count = click_count + 1, last_click_at = NOW(), updated_at = NOW()');
        $stmt->execute([$scenarioId, $blockId]);
    } catch (Throwable $e) {
        try { asr_tg_log($pdo, 0, 'warning', 'scenario_stats_click_increment_failed', $e->getMessage(), ['scenario_id' => $scenarioId, 'block_id' => $blockId]); } catch (Throwable $ignored) {}
    }
}

function asr_tg_scenario_stats_reset(PDO $pdo, int $scenarioId): void {
    if ($scenarioId <= 0) throw new RuntimeException('Не указан сценарий для сброса статистики.');
    asr_tg_scenario_stats_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM oca_telegram_bot_scenario_block_stats WHERE scenario_id = ?');
    $stmt->execute([$scenarioId]);
}
