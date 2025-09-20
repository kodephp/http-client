<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\HttpClient\Factory;
use Kode\Context\Context;

// 创建一个简单的日志记录器
$logger = function (string $message) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
};

// 使用工厂创建带有中间件的 HTTP 客户端
$client = Factory::create([
    'timeout' => 10.0,
    'retries' => 3,
    'logger' => $logger
]);

// 创建一个请求
$request = new \GuzzleHttp\Psr7\Request('GET', 'https://httpbin.org/delay/2');

// 创建上下文
$context = (new Context())->withTimeout(5.0);

try {
    // 发送请求
    $response = $client->sendRequest($request, $context);
    
    echo "Status Code: " . $response->getStatusCode() . PHP_EOL;
    echo "Response Body: " . $response->getBody() . PHP_EOL;
} catch (\Exception $e) {
    echo "Request failed: " . $e->getMessage() . PHP_EOL;
}