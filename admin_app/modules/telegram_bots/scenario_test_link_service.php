<?php
defined('ASR_ADMIN') || exit;

/**
 * Изолированный слой ссылок для тестирования сценария с конкретного блока.
 * Не пишет состояние подписчика, не создаёт диплинки в БД и не вмешивается в runtime.
 */
function asr_tg_scenario_test_link_for_block(PDO $pdo, int $scenarioId, int $blockId): array {
    $empty = [
        'enabled' => false,
        'url' => '',
        'code' => '',
        'reason' => 'Ссылка для тестирования недоступна.',
    ];
    if ($scenarioId <= 0 || $blockId <= 0) return $empty;

    try {
        if (function_exists('asr_tg_repository_ensure_scenario_schema')) {
            asr_tg_repository_ensure_scenario_schema($pdo);
        }

        $block = function_exists('asr_tg_scenario_block_find') ? asr_tg_scenario_block_find($pdo, $blockId, $scenarioId) : null;
        if (!$block) {
            return $empty + ['reason' => 'Блок сценария не найден.'];
        }
        if ((string)($block['type'] ?? '') === 'start') {
            return $empty + ['reason' => 'Стартовый блок тестируется обычным /start.'];
        }

        $botId = 0;
        if (function_exists('asr_tg_scenario_default_bot_id')) {
            $botId = (int)asr_tg_scenario_default_bot_id($pdo, $scenarioId);
        } elseif (function_exists('asr_tg_scenario_bot_id')) {
            $botId = (int)asr_tg_scenario_bot_id($pdo, $scenarioId);
        }
        if ($botId <= 0) {
            return $empty + ['reason' => 'У сценария не выбран Telegram-канал.'];
        }

        $bot = function_exists('asr_tg_bot_find_light') ? asr_tg_bot_find_light($pdo, $botId) : (function_exists('asr_tg_bot_find') ? asr_tg_bot_find($pdo, $botId) : null);
        if (!is_array($bot)) {
            return $empty + ['reason' => 'Telegram-канал сценария не найден.'];
        }

        $channelType = function_exists('asr_tg_channel_type_of') ? (string)asr_tg_channel_type_of($bot) : (string)($bot['channel_type'] ?? 'telegram');
        if ($channelType !== '' && $channelType !== 'telegram') {
            return $empty + ['reason' => 'Тестирование ссылкой сейчас доступно только для Telegram.'];
        }

        $username = ltrim(trim((string)($bot['bot_username'] ?? '')), '@');
        if ($username === '') {
            return $empty + ['reason' => 'У Telegram-канала не указан username бота.'];
        }

        // Runtime уже поддерживает безопасный детерминированный формат dl-{scenario_id}-{block_id}.
        // Поэтому для теста не создаём отдельные записи в таблице диплинков.
        $code = 'dl-' . $scenarioId . '-' . $blockId;
        return [
            'enabled' => true,
            'url' => 'https://t.me/' . rawurlencode($username) . '?start=' . rawurlencode($code),
            'code' => $code,
            'reason' => '',
        ];
    } catch (Throwable $e) {
        return $empty + ['reason' => 'Не удалось собрать ссылку для тестирования.'];
    }
}
