<?php
/**
 * Синхронизация результата теста OCA с Битрикс24.
 * Поддерживает 2 сценария:
 * 1) после первых 20 ответов создаёт/обновляет контакт и создаёт сделку в промежуточной стадии;
 * 2) после полного завершения переносит эту же сделку в финальную стадию и дозаписывает итоговые ссылки/поля.
 */

require_once __DIR__ . '/api.php';

function sendTestToBitrix24($data) {
    return completeTestInBitrix24($data);
}

function sendPartialTestToBitrix24($data) {
    $contactId = findOrCreateBitrix24Contact($data);
    if (!$contactId) {
        return false;
    }

    $dealId = extractBitrix24DealId($data['deal_url'] ?? '');
    if ($dealId > 0) {
        $ok = updateBitrix24PartialDeal($dealId, $data, $contactId);
        // Если сделку удалили в Bitrix24 или update вернул ошибку, создаём новую.
        if (!$ok) {
            $dealId = createBitrix24PartialDeal($data, $contactId);
            $ok = $dealId > 0;
        }
    } else {
        $dealId = createBitrix24PartialDeal($data, $contactId);
        $ok = $dealId > 0;
    }

    if (!$ok || !$dealId) {
        return false;
    }

    return [
        'contact_id' => $contactId,
        'deal_id' => $dealId,
        'contact_url' => buildBitrix24ContactUrl($contactId),
        'deal_url' => buildBitrix24DealUrl($dealId)
    ];
}

function completeTestInBitrix24($data) {
    $contactId = findOrCreateBitrix24Contact($data);
    if (!$contactId) {
        return false;
    }

    $dealId = extractBitrix24DealId($data['deal_url'] ?? '');
    if ($dealId > 0) {
        $ok = updateBitrix24DealToCompleted($dealId, $data, $contactId);
        // Если сделку удалили в Bitrix24 или update вернул ошибку, создаём новую.
        if (!$ok) {
            $dealId = createBitrix24Deal($data, $contactId);
            $ok = $dealId > 0;
        }
    } else {
        $dealId = createBitrix24Deal($data, $contactId);
        $ok = $dealId > 0;
    }

    if (!$ok || !$dealId) {
        return false;
    }

    return [
        'contact_id' => $contactId,
        'deal_id' => $dealId,
        'contact_url' => buildBitrix24ContactUrl($contactId),
        'deal_url' => buildBitrix24DealUrl($dealId)
    ];
}

function findOrCreateBitrix24Contact($data) {
    $contactId = findBitrix24Contact($data);

    if (!$contactId) {
        $contactId = createBitrix24Contact($data);
    } else {
        updateBitrix24Contact($contactId, $data);
    }

    return $contactId ? (int)$contactId : false;
}

function findBitrix24Contact($data) {
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');

    if ($phone !== '') {
        $result = executeB24Method('crm.duplicate.findbycomm', [
            'type' => 'PHONE',
            'values' => [$phone]
        ]);

        if (!empty($result['CONTACT'][0])) {
            return (int)$result['CONTACT'][0];
        }
    }

    if ($email !== '') {
        $result = executeB24Method('crm.duplicate.findbycomm', [
            'type' => 'EMAIL',
            'values' => [$email]
        ]);

        if (!empty($result['CONTACT'][0])) {
            return (int)$result['CONTACT'][0];
        }
    }

    return false;
}

function createBitrix24Contact($data) {
    $nameParts = splitFullName($data['name'] ?? '');

    $fields = [
        'NAME' => $nameParts['name'],
        'LAST_NAME' => $nameParts['last_name'],
        'OPENED' => 'Y',
        'TYPE_ID' => 'CLIENT',
        'SOURCE_ID' => 'WEB'
    ];

    if (!empty($data['city'])) {
        $fields[B24_UF_CONTACT_CITY] = $data['city'];
    }

    if (!empty($data['phone'])) {
        $fields['PHONE'] = [
            [
                'VALUE' => $data['phone'],
                'VALUE_TYPE' => 'WORK'
            ]
        ];
    }

    if (!empty($data['email'])) {
        $fields['EMAIL'] = [
            [
                'VALUE' => $data['email'],
                'VALUE_TYPE' => 'WORK'
            ]
        ];
    }

    $contactId = executeB24Method('crm.contact.add', [
        'fields' => $fields
    ]);

    return $contactId ? (int)$contactId : false;
}

function updateBitrix24Contact($contactId, $data) {
    $fields = [];

    if (!empty($data['city'])) {
        $fields[B24_UF_CONTACT_CITY] = $data['city'];
    }

    if (empty($fields)) {
        return true;
    }

    return executeB24Method('crm.contact.update', [
        'id' => $contactId,
        'fields' => $fields
    ]);
}

function baseBitrix24DealFields($data, $contactId, $stageId) {
    $dealFields = [
        'TITLE' => 'Тест АСР: ' . (!empty($data['name']) ? $data['name'] : 'Без имени'),
        'CATEGORY_ID' => B24_DEAL_CATEGORY_ID,
        'STAGE_ID' => $stageId,
        'CONTACT_ID' => $contactId,
        'OPENED' => 'Y',
        'SOURCE_ID' => 'WEB',
        B24_UF_DEAL_CITY => $data['city'] ?? '',
        B24_UF_DEAL_ROLE => $data['role'] ?? ''
    ];

    if (!empty($data['utm'])) {
        parseUtmStringToDealFields($data['utm'], $dealFields);
    }

    return $dealFields;
}

function createBitrix24PartialDeal($data, $contactId) {
    $dealFields = baseBitrix24DealFields($data, $contactId, B24_DEAL_STAGE_PARTIAL_ID);
    $dealFields[B24_UF_RESUME_LINK] = $data['resume_link'] ?? '';

    $dealId = executeB24Method('crm.deal.add', [
        'fields' => $dealFields
    ]);

    return $dealId ? (int)$dealId : false;
}

function updateBitrix24PartialDeal($dealId, $data, $contactId) {
    $dealFields = baseBitrix24DealFields($data, $contactId, B24_DEAL_STAGE_PARTIAL_ID);
    $dealFields[B24_UF_RESUME_LINK] = $data['resume_link'] ?? '';

    return executeB24Method('crm.deal.update', [
        'id' => (int)$dealId,
        'fields' => $dealFields
    ]);
}

function createBitrix24Deal($data, $contactId) {
    $dealFields = completedBitrix24DealFields($data, $contactId);

    $dealId = executeB24Method('crm.deal.add', [
        'fields' => $dealFields
    ]);

    return $dealId ? (int)$dealId : false;
}

function updateBitrix24DealToCompleted($dealId, $data, $contactId) {
    $dealFields = completedBitrix24DealFields($data, $contactId);

    return executeB24Method('crm.deal.update', [
        'id' => (int)$dealId,
        'fields' => $dealFields
    ]);
}

function completedBitrix24DealFields($data, $contactId) {
    $dealFields = baseBitrix24DealFields($data, $contactId, B24_DEAL_STAGE_ID);
    $dealFields[B24_UF_TEST_MANAGER] = $data['manager_link'] ?? '';
    $dealFields[B24_UF_TEST_CLIENT] = $data['client_link'] ?? '';
    $dealFields[B24_UF_TEST_DATE] = date('Y-m-d H:i:s');

    return $dealFields;
}

function extractBitrix24DealId($dealUrl) {
    $dealUrl = trim((string)$dealUrl);
    if ($dealUrl === '') {
        return 0;
    }

    if (preg_match('~/crm/deal/details/(\d+)/~', $dealUrl, $m)) {
        return (int)$m[1];
    }

    if (ctype_digit($dealUrl)) {
        return (int)$dealUrl;
    }

    return 0;
}

function parseUtmStringToDealFields($utm, &$dealFields) {
    $utm = html_entity_decode($utm, ENT_QUOTES, 'UTF-8');
    $utm = str_replace('|', '&', $utm);

    parse_str($utm, $parsed);

    $map = [
        'utm_source' => 'UTM_SOURCE',
        'utm_medium' => 'UTM_MEDIUM',
        'utm_campaign' => 'UTM_CAMPAIGN',
        'utm_content' => 'UTM_CONTENT',
        'utm_term' => 'UTM_TERM'
    ];

    foreach ($map as $sourceKey => $dealKey) {
        if (!empty($parsed[$sourceKey])) {
            $dealFields[$dealKey] = $parsed[$sourceKey];
        }
    }
}

function splitFullName($fullName) {
    $fullName = trim($fullName);

    if ($fullName === '') {
        return [
            'name' => 'Без имени',
            'last_name' => ''
        ];
    }

    $parts = preg_split('/\s+/u', $fullName);

    return [
        'name' => $parts[0] ?? $fullName,
        'last_name' => $parts[1] ?? ''
    ];
}

function buildBitrix24ContactUrl($contactId) {
    return rtrim((defined('B24_PORTAL_URL') ? B24_PORTAL_URL : (asr_get_setting('b24_portal_url', 'https://exec-booster.bitrix24.kz'))), '/') . '/crm/contact/details/' . (int)$contactId . '/';
}

function buildBitrix24DealUrl($dealId) {
    return rtrim((defined('B24_PORTAL_URL') ? B24_PORTAL_URL : (asr_get_setting('b24_portal_url', 'https://exec-booster.bitrix24.kz'))), '/') . '/crm/deal/details/' . (int)$dealId . '/';
}
