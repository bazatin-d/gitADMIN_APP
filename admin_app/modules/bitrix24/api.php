<?php
/**
 * Библиотека для выполнения запросов к REST API Битрикс24.
 * Логи ротируются и не содержат webhook URL/пароли целиком.
 */
require_once __DIR__ . '/config.php';

function asr_b24_mask_secret($value) {
    $value = (string)$value;
    $value = preg_replace('~https://([^/]+)/rest/\d+/[^/]+/~', 'https://$1/rest/***/***/', $value) ?? $value;
    $value = preg_replace('/([A-Za-z0-9_\-]{16,})/', '***', $value) ?? $value;
    return $value;
}

function asr_b24_log_safe($data) {
    if (is_array($data)) {
        $clean = [];
        foreach ($data as $k => $v) {
            $key = (string)$k;
            if (preg_match('/password|token|webhook|secret|key/i', $key)) {
                $clean[$k] = '***';
            } else {
                $clean[$k] = asr_b24_log_safe($v);
            }
        }
        return $clean;
    }
    return is_string($data) ? asr_b24_mask_secret($data) : $data;
}

function asr_b24_rotate_log(string $path, int $maxBytes = 5242880): void {
    if (!is_file($path) || filesize($path) < $maxBytes) return;
    @rename($path . '.2', $path . '.3');
    @rename($path . '.1', $path . '.2');
    @rename($path, $path . '.1');
}

function asr_b24_write_log(string $filename, string $method, $params, string $curlError, string $rawResult): void {
    $legacyLogDir = dirname(__DIR__, 3) . '/b24_integration';
    $path = (is_dir($legacyLogDir) ? $legacyLogDir : __DIR__) . '/' . $filename;
    asr_b24_rotate_log($path);
    $text = date('Y-m-d H:i:s')
        . "\nMETHOD: " . $method
        . "\nPARAMS: " . print_r(asr_b24_log_safe($params), true)
        . "\nCURL_ERROR: " . asr_b24_mask_secret($curlError)
        . "\nRAW_RESULT: " . asr_b24_mask_secret($rawResult)
        . "\n\n";
    @file_put_contents($path, $text, FILE_APPEND);
}

function executeB24Method($method, $params = []) {
    $url = B24_WEBHOOK_URL . $method . '.json';

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => $url,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ));

    $result = curl_exec($curl);
    $curl_error = curl_error($curl);
    curl_close($curl);

    $raw = is_string($result) ? $result : '';
    $response = json_decode($raw, true);

    if (defined('B24_DEBUG') && B24_DEBUG) {
        asr_b24_write_log('b24_debug.log', $method, $params, $curl_error, $raw);
    }
    if ($curl_error || !isset($response['result'])) {
        asr_b24_write_log('b24_error.log', $method, $params, $curl_error, $raw);
    }

    return $response['result'] ?? false;
}
