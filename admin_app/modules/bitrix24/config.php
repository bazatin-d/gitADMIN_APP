<?php
/**
 * Настройки интеграции с Битрикс24.
 * Значения берутся из oca_settings, если таблица создана. Если нет — используются старые значения по умолчанию.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $configPath = dirname(__DIR__, 3) . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }
}

$settingsPath = dirname(__DIR__, 2) . '/lib/settings.php';
if (file_exists($settingsPath)) {
    require_once $settingsPath;
}

$get = function(string $key, $fallback = '') {
    return function_exists('asr_get_setting') ? asr_get_setting($key, $fallback) : $fallback;
};

$webhook = rtrim((string)$get('b24_webhook_url', 'https://exec-booster.bitrix24.kz/rest/11/5lh1nh17l56ummxs/'), '/') . '/';
$portal = rtrim((string)$get('b24_portal_url', 'https://exec-booster.bitrix24.kz'), '/');

if (!defined('B24_WEBHOOK_URL')) define('B24_WEBHOOK_URL', $webhook);
if (!defined('B24_PORTAL_URL')) define('B24_PORTAL_URL', $portal);

if (!defined('B24_DEAL_CATEGORY_ID')) define('B24_DEAL_CATEGORY_ID', (int)$get('b24_deal_category_id', '13'));
if (!defined('B24_DEAL_STAGE_ID')) define('B24_DEAL_STAGE_ID', (string)$get('b24_deal_stage_id', 'C13:NEW'));
if (!defined('B24_DEAL_STAGE_PARTIAL_ID')) define('B24_DEAL_STAGE_PARTIAL_ID', (string)$get('b24_deal_stage_partial_id', 'C13:UC_VENTXV'));

if (!defined('B24_UF_TEST_MANAGER')) define('B24_UF_TEST_MANAGER', (string)$get('b24_uf_test_manager', 'UF_CRM_1778755158529'));
if (!defined('B24_UF_TEST_CLIENT')) define('B24_UF_TEST_CLIENT', (string)$get('b24_uf_test_client', 'UF_CRM_1779105865452'));
if (!defined('B24_UF_RESUME_LINK')) define('B24_UF_RESUME_LINK', (string)$get('b24_uf_resume_link', 'UF_CRM_1779782130788'));
if (!defined('B24_UF_TEST_DATE')) define('B24_UF_TEST_DATE', (string)$get('b24_uf_test_date', 'UF_CRM_1778818756245'));
if (!defined('B24_UF_DEAL_CITY')) define('B24_UF_DEAL_CITY', (string)$get('b24_uf_deal_city', 'UF_CRM_1709107813313'));
if (!defined('B24_UF_DEAL_ROLE')) define('B24_UF_DEAL_ROLE', (string)$get('b24_uf_deal_role', 'UF_CRM_1778819338182'));
if (!defined('B24_UF_CONTACT_CITY')) define('B24_UF_CONTACT_CITY', (string)$get('b24_uf_contact_city', 'UF_CRM_1709108400679'));
if (!defined('B24_DEBUG')) define('B24_DEBUG', (string)$get('b24_debug', '0') === '1');
