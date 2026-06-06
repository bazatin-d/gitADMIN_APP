<?php
defined('ASR_ADMIN') || exit;

/**
 * Единая совместимая прокладка для старых страниц.
 *
 * Старые пути вида admin_app/views/pages/{page}.php оставлены только для обратной
 * совместимости. Реальные страницы живут в admin_app/modules/{module}/pages/.
 * Новую логику сюда не добавляем.
 */
function asr_legacy_page_require(string $moduleId, string $page = 'index'): void {
    $moduleId = trim($moduleId);
    $page = trim($page) ?: 'index';

    if (!preg_match('/^[a-z0-9_\-]+$/', $moduleId) || !preg_match('/^[a-z0-9_\-]+$/', $page)) {
        http_response_code(404);
        echo '<div class="card"><h2>Страница не найдена</h2><p>Некорректный путь страницы.</p></div>';
        return;
    }

    $target = dirname(__DIR__, 2) . '/modules/' . $moduleId . '/pages/' . $page . '.php';
    if (!is_file($target)) {
        http_response_code(404);
        echo '<div class="card"><h2>Страница не найдена</h2><p>Файл модуля отсутствует.</p></div>';
        return;
    }

    require $target;
}
