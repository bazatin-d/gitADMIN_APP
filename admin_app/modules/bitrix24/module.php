<?php

return [
    'id' => 'bitrix24',
    'title' => 'Bitrix24',
    'description' => 'Интеграция с Bitrix24: контакты, сделки, ссылки на результаты и промежуточные сделки.',
    'actions' => __DIR__ . '/actions.php',
    'service' => __DIR__ . '/service.php',
    'permission' => 'admin',
];
