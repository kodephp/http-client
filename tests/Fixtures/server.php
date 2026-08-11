<?php

/**
 * 集成测试用的最小 HTTP 服务
 *
 * 由 PHP 内置服务器加载：php -S 127.0.0.1:<port> tests/Fixtures/server.php
 *
 * @package Kode\HttpClient\Tests\Fixtures
 * @license MIT
 */

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * 收集请求头
 *
 * @return array<string, string>
 */
$collectHeaders = static function (): array {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
            $headers[$name] = (string) $value;
        }
    }

    return $headers;
};

switch (true) {
    case $path === '/ok':
        header('Content-Type: application/json');
        header('X-Final-Hop: yes');
        echo json_encode(['ok' => true, 'path' => $path], JSON_THROW_ON_ERROR);
        break;

    case $path === '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $method,
            'query' => $_GET,
            'headers' => $collectHeaders(),
            'body' => file_get_contents('php://input'),
        ], JSON_THROW_ON_ERROR);
        break;

    case $path === '/redirect':
        header('X-Hop: first', true, 302);
        header('Location: /ok');
        echo 'redirecting';
        break;

    case $path === '/gzip':
        $payload = json_encode(['compressed' => true, 'filler' => str_repeat('x', 200)], JSON_THROW_ON_ERROR);
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode($payload);
        break;

    case $path === '/slow':
        $ms = (int) ($_GET['ms'] ?? 500);
        usleep($ms * 1000);
        header('Content-Type: text/plain');
        echo 'slept ' . $ms;
        break;

    case preg_match('#^/status/(\d{3})$#', $path, $m) === 1:
        http_response_code((int) $m[1]);
        header('Content-Type: text/plain');
        echo 'status ' . $m[1];
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'not found';
        break;
}
