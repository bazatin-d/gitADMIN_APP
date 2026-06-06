<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'emails',
    'title' => 'Письма и SMTP',
    'tab' => 'emails',
    'permission' => 'admin',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
];
