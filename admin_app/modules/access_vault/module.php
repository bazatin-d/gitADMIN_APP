<?php
return [
    'id' => 'access_vault',
    'title' => 'Доступы',
    'tab' => 'access_vault',
    'permission' => 'access_vault.view',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'assets_js' => ['/assets/admin/modules/access_vault/access_vault.js'],
];
