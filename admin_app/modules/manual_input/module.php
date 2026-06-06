<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'manual_input',
    'title' => 'Ввести вручную',
    'tab' => 'manual_input',
    'permission' => 'work_results',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'tables' => ['oca_results'],
    'assets_js' => [
        '/assets/admin/modules/manual_input/manual_input.js',
    ],
];
