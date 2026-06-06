<?php
return [
    'id' => 'settings',
    'title' => 'Настройки',
    'tab' => 'settings',
    'permission' => 'admin',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'tables' => [
        'oca_settings',
    ],
];
