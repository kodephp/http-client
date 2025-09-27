<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\HttpClient\Factory;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;

/**
 * 改进后的客户端使用示例
 * 
 * 展示了增强功能的使用方法
 */

// 创建带有多种中间件的客户端
$client = Factory::create([
    'timeout' => 30.0,
    'retries' => 3,
    'auth' => [
        'type' => 'bearer',
        'token' => 'your-api-token'
    ],
    'rate_limit' => [
        'capacity' => 10,
        'rate' => 2
    ],
    'cache_ttl' => 60
]);

echo "=== 改进后的 HTTP 客户端示例 ===\n\n";

// 示例1: 基本请求（带超时）
echo "1. 基本请求（带超时控制）:\n";
try {
    $response = $client->get('https://httpbin.org/get');
    echo "状态码: " . $response->getStatusCode() . "\n";
    echo "响应: " . substr((string)$response->getBody(), 0, 100) . "...\n\n";
} catch (Exception $e) {
    echo "请求失败: " . $e->getMessage() . "\n\n";
}

// 示例2: 使用自定义超时
echo "2. 使用自定义超时:\n";
try {
    // 创建一个带自定义超时的请求
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://httpbin.org/delay/1');
    
    // 设置较短的超时时间（1秒）
    $context = new \Kode\HttpClient\Context\Context();
    $context = $context->withTimeout(1.0);
    
    $response = $client->sendRequest($request, $context);
    echo "状态码: " . $response->getStatusCode() . "\n\n";
} catch (Exception $e) {
    echo "请求超时: " . $e->getMessage() . "\n\n";
}

// 示例3: 重试机制
echo "3. 重试机制演示:\n";
try {
    // 创建一个可能失败的请求
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://httpbin.org/status/500');
    
    // 设置重试次数
    $context = new \Kode\HttpClient\Context\Context();
    $context = $context->withRetryCount(3);
    
    $response = $client->sendRequest($request, $context);
    echo "状态码: " . $response->getStatusCode() . "\n\n";
} catch (Exception $e) {
    echo "请求最终失败: " . $e->getMessage() . "\n\n";
}

echo "=== 示例结束 ===\n";

/**
 * 手动创建客户端并添加中间件的方式
 */
echo "\n=== 手动配置客户端示例 ===\n\n";

$manualClient = new \Kode\HttpClient\HttpClient();

// 添加各种中间件
$manualClient->addMiddleware(new TimeoutMiddleware(5.0));
$manualClient->addMiddleware(new RetryMiddleware(3));
$manualClient->addMiddleware(AuthMiddleware::bearer('your-api-token'));
$manualClient->addMiddleware(new RateLimitMiddleware(10, 2));
$manualClient->addMiddleware(new CacheMiddleware(60));

// 添加日志中间件
$logger = function (string $message) {
    echo "[LOG] " . $message . "\n";
};
$manualClient->addMiddleware(new LoggingMiddleware($logger));

echo "手动配置的客户端已创建，可以使用所有增强功能。\n";