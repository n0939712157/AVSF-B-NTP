<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once AVSF_CORE_DIR . '/analytics/recent_page_views.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function avsfPageViewRespond($status, $message = '') {
    http_response_code($status);
    if ($message !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    avsfPageViewRespond(405, 'Method not allowed');
}

$origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : '';
$requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($origin !== '' && (!is_string($requestHost) || strtolower($origin) !== strtolower($requestHost))) {
    avsfPageViewRespond(403, 'Origin not allowed');
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 4096) {
    avsfPageViewRespond(413, 'Request too large');
}
$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload['path'], $payload['title']) || !is_string($payload['path']) || !is_string($payload['title'])) {
    avsfPageViewRespond(400, 'Invalid page-view data');
}

$path = $payload['path'];
$title = $payload['title'];
if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '?') !== false || strpos($path, '#') !== false || preg_match('/[\x00-\x1F\x7F]/', $path)) {
    avsfPageViewRespond(400, 'Invalid page path');
}

$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = rtrim($basePath, '/');
if ($basePath === '.') {
    $basePath = '';
}
if (($basePath !== '' && $path !== $basePath && strpos($path, $basePath . '/') !== 0) || preg_match('~(?:^|/)(?:admin-login|admin)(?:/|$)~i', $path)) {
    avsfPageViewRespond(400, 'Page path is not trackable');
}

if (strlen($title) > 1024) {
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 255, 'UTF-8') : substr($title, 0, 255);
}
if (!avsfRecentPageViewsEnsureTable($conn) || !avsfRecentPageViewsRecord($conn, $path, $title)) {
    error_log('AVSF recent page-view write failed: ' . mysqli_error($conn));
    avsfPageViewRespond(500, 'Could not record page view');
}

http_response_code(204);
