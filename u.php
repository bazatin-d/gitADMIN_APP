<?php
/**
 * Основной обработчик коротких ссылок. Домены задаются в config.php.
 * Именно здесь идёт подключение к БД, поиск ссылки и увеличение счётчика.
 */

require_once __DIR__ . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка подключения к базе данных.';
    exit;
}

$slug = trim((string)($_GET['c'] ?? ''));
if ($slug === '') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = trim((string)$path, '/');
    if (strpos($path, 'u/') === 0) {
        $path = substr($path, 2);
    }
    $slug = trim($path, '/');
}

if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]{3,64}$/', $slug)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ссылка не найдена.';
    exit;
}

try {
    // Ищем по хвостику без жёсткой привязки к текущему host.
    // Это нужно, потому что короткая ссылка может открываться на отдельном домене коротких ссылок,
    $stmt = $pdo->prepare("SELECT id, normalized_url FROM oca_short_urls WHERE slug = ? ORDER BY (domain = ?) DESC, id DESC LIMIT 1");
    $stmt->execute([$slug, defined('SHORTENER_DOMAIN') ? SHORTENER_DOMAIN : '']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['normalized_url'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ссылка не найдена.';
        exit;
    }

    $upd = $pdo->prepare("UPDATE oca_short_urls SET clicks = clicks + 1 WHERE id = ?");
    $upd->execute([(int)$row['id']]);

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $row['normalized_url'], true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка перехода по ссылке.';
    exit;
}
