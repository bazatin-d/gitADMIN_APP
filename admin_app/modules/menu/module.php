<?php
/**
 * Манифест модуля редактируемого меню.
 */

defined('ASR_ADMIN') || exit;

return [
    'id' => 'menu',
    'title' => 'Меню админки',
    'permission' => 'admin',
    'actions' => __DIR__ . '/actions.php',
    'service' => __DIR__ . '/service.php',
    'repository' => __DIR__ . '/repository.php',
    'settings_block' => __DIR__ . '/pages/settings_block.php',
];
