<?php
defined('ASR_ADMIN') || exit;

return [
    'id' => 'url_shortener',
    'title' => 'URL Shortener',
    'tab' => 'url_shortener',
    'menu_title' => 'URL Shortener',
    'permission' => 'public',
    'page' => __DIR__ . '/pages/index.php',
    'actions' => __DIR__ . '/actions.php',
    'service' => __DIR__ . '/service.php',
    'repository' => __DIR__ . '/repository.php',
    'assets_js' => [
        '/assets/admin/modules/url_shortener/url_shortener.js',
    ],
];
