<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'users',
    'title' => 'Пользователи',
    'tab' => 'users',
    'permission' => 'admin',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'tables' => ['oca_users'],
];
