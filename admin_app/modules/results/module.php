<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'results',
    'title' => 'Результаты АСР',
    'tab' => 'results',
    'permission' => 'results',
    'page' => __DIR__ . '/pages/index.php',
    'detail_page' => __DIR__ . '/pages/detail.php',
    'actions' => __DIR__ . '/actions.php',
];
