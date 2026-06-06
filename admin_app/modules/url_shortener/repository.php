<?php
defined('ASR_ADMIN') || exit;

/**
 * Репозиторий модуля URL Shortener.
 *
 * На первом этапе модульного рефакторинга низкоуровневые SQL-запросы пока
 * сохранены в service.php, чтобы не менять поведение рабочей страницы.
 * Следующий безопасный шаг — постепенно перенести сюда SELECT/INSERT/UPDATE/DELETE
 * для таблиц oca_short_urls и oca_utm_presets.
 */
