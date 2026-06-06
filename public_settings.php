<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_app/lib/settings.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$url = trim((string)asr_get_setting('agreement_url', function_exists('asr_config_agreement_url') ? asr_config_agreement_url() : '/agreement.html'));
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    $url = 'https://' . ltrim($url, '/');
}

echo json_encode([
    'agreement_url' => $url,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
