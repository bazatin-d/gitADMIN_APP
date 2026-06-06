<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'import_export',
    'title' => 'Импорт/экспорт',
    'tab' => 'import_export',
    'permission' => 'admin',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
];
