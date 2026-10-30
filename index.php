<?php
/**
 * TECH CONNECT WiFi - front controller
 *
 * Serves the static front end, executes PHP under backend/ and at the root,
 * and sends the root to the front end.
 */

$path = ltrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

function serve_static(string $file): void
{
    $types = [
        'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    readfile($file);
}

function run_php(string $file, string $baseDir): void
{
    chdir($baseDir);
    include $file;
}

if ($path === '' || $path === 'index.php') {
    header('Location: /frontend/index.html');
    exit;
}

// These directories execute PHP. A directory path resolves to its index.php.
foreach (['backend'] as $phpDir) {
    if (!str_starts_with($path, $phpDir . '/')) {
        continue;
    }
    $file = __DIR__ . '/' . $path;
    if (is_dir($file)) {
        $dir = rtrim($file, '/');
        if (is_file($dir . '/index.php')) {
            run_php($dir . '/index.php', __DIR__ . '/' . $phpDir);
            exit;
        }
        if (is_file($dir . '/index.html')) {
            serve_static($dir . '/index.html');
            exit;
        }
    }
    if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        run_php($file, __DIR__ . '/' . $phpDir);
        exit;
    }
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Endpoints that read the rest of the URL from REQUEST_URI, so they are mounted
// by prefix rather than by file path.
$prefixRoutes = [
    'provision' => 'provision.php',
    'templates' => 'templates.php',
];
$firstSegment = explode('/', $path)[0];
if (isset($prefixRoutes[$firstSegment])) {
    run_php(__DIR__ . '/' . $prefixRoutes[$firstSegment], __DIR__);
    exit;
}

// Everything else under frontend/ is static. A directory resolves to index.html.
if (str_starts_with($path, 'frontend/')) {
    $file = __DIR__ . '/' . $path;
    if (is_dir($file)) {
        $file = rtrim($file, '/') . '/index.html';
    }
    if (is_file($file)) {
        serve_static($file);
        exit;
    }
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Root level entries such as health.php are reachable directly.
$rootFile = __DIR__ . '/' . $path;
if (!str_contains($path, '/') && is_file($rootFile)
    && strtolower(pathinfo($rootFile, PATHINFO_EXTENSION)) === 'php') {
    run_php($rootFile, __DIR__);
    exit;
}

http_response_code(404);
echo 'Not found';
