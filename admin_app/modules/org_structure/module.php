<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'org_structure',
    'title' => 'Оргсхемы',
    'tab' => 'org_structure',
    'permission' => 'admin',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
];
