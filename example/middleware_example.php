<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\HttpClient\Factory;
use Kode\Context\Context;
use GuzzleHttp\Psr7\Request;

// 创建一个简单的日志记录器
$logger = function (string $message) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
};

echo "=== HTTP Client with Middleware Example ===\n\n";

// 1. 基本使用（带所有新中间件）
echo "1. Creating client with all middleware...\n";
$client = Factory::create([
    'timeout' => 10.0,
    'retries' => 3,
    'logger' => $logger,
    'auth' => [
        'type' => 'bearer',
        'credential' => 'your-bearer-token'
    ],
    'rate_limit' => [
        'capacity' => 5,
        'rate' => 1
    ],
    'cache' => true
]);

// 创建一个请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求
echo "Sending request...\n";
try {
    $response = $client->sendRequest($request);
    echo "Status Code: " . $response->getStatusCode() . PHP_EOL;
    echo "Response received successfully.\n\n";
} catch (\Exception $e) {
    echo "Request failed: " . $e->getMessage() . PHP_EOL;
}

// 2. 单独使用认证中间件
echo "2. Using Auth Middleware...\n";
$authClient = Factory::create([
    'auth' => [
        'type' => 'api_key',
        'credential' => 'your-api-key',
        'header' => 'X-API-Key'
    ]
]);

$authRequest = new Request('GET', 'https://httpbin.org/headers');
try {
    $response = $authClient->sendRequest($authRequest);
    echo "Auth request status: " . $response->getStatusCode() . PHP_EOL;
    echo "Response received successfully.\n\n";
} catch (\Exception $e) {
    echo "Auth request failed: " . $e->getMessage() . PHP_EOL;
}

// 3. 使用限流中间件
echo "3. Using Rate Limit Middleware...\n";
$rateLimitedClient = Factory::create([
    'rate_limit' => [
        'capacity' => 2,
        'rate' => 1 // 每秒1个请求
    ]
]);

// 发送多个请求测试限流
for ($i = 0; $i < 3; $i++) {
    try {
        $request = new Request('GET', 'https://httpbin.org/get');
        $response = $rateLimitedClient->sendRequest($request);
        echo "Rate limited request $i status: " . $response->getStatusCode() . PHP_EOL;
    } catch (\Exception $e) {
        echo "Rate limited request $i failed: " . $e->getMessage() . PHP_EOL;
    }
}

echo "\nExample completed.\n";