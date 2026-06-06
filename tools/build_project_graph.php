<?php
/**
 * Генерирует docs/generated_file_tree.md.
 * Запускать из корня проекта:
 * php tools/build_project_graph.php
 */

$root = dirname(__DIR__);
$output = $root . '/docs/generated_file_tree.md';

$includeDirs = [
    'admin_app/core',
    'admin_app/actions',
    'admin_app/lib',
    'admin_app/modules',
    'admin_app/views',
    'assets/admin',
    'database/migrations',
    'database/seeds',
    'b24_integration',
    'tools',
    'docs',
];

$excludePatterns = [
    '/\.log$/i',
    '/\.zip$/i',
    '/\.sql\.bak$/i',
    '/\.bak$/i',
    '/\.old$/i',
    '/node_modules/i',
    '/vendor/i',
    '/\.git/i',
];

function should_skip_path(string $relative, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $relative)) {
            return true;
        }
    }
    return false;
}

$lines = [];
$lines[] = '# Generated File Tree';
$lines[] = '';
$lines[] = 'Сгенерировано: ' . date('Y-m-d H:i:s');
$lines[] = '';
$lines[] = 'Этот файл создаётся автоматически. Ручные пояснения хранить в `docs/project_graph.md`.';
$lines[] = '';

foreach ($includeDirs as $dir) {
    $abs = $root . '/' . $dir;
    if (!is_dir($abs)) {
        continue;
    }

    $lines[] = '## ' . $dir;
    $lines[] = '';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace($root . '/', '', $file->getPathname());
        $relative = str_replace('\\', '/', $relative);
        if (should_skip_path($relative, $excludePatterns)) {
            continue;
        }
        $files[] = $relative;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($files as $file) {
        $lines[] = '- `' . $file . '`';
    }

    $lines[] = '';
}

if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0755, true);
}

file_put_contents($output, implode("\n", $lines) . "\n");

echo "Generated: docs/generated_file_tree.md\n";
