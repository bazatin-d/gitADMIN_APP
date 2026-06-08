<?php
defined('ASR_ADMIN') || exit;

/**
 * Минимальный каркас VK API-клиента.
 * Реальные отправки сообщений будут подключаться отдельным патчем после проверки Callback API.
 */

if (!function_exists('asr_tg_vk_api_version')) {
    function asr_tg_vk_api_version(): string {
        return '5.199';
    }
}

if (!function_exists('asr_tg_vk_channel_has_token')) {
    function asr_tg_vk_channel_has_token(array $channel): bool {
        return trim((string)($channel['vk_api_token_encrypted'] ?? '')) !== '';
    }
}
