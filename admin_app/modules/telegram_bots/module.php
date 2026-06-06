<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'telegram_bots',
    'title' => 'Чат-боты',
    'tab' => 'telegram_bots',
    'menu_title' => 'Чат-боты',
    'permission' => 'telegram_bots.view',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'service' => __DIR__ . '/service.php',
    'repository' => __DIR__ . '/repository.php',
];
