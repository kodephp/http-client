<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\HttpClient\Factory;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * 基础用法：创建客户端、发送请求、读取响应。
 *
 * 需要联网（使用 httpbin.org）。运行：php example/quickstart.php
 */

$client = Factory::create([
    'timeout' => 10.0,
    'retries' => 2,
    'headers' => ['Accept' => 'application/json'],
]);

echo "=== GET（带查询参数）===\n";
try {
    $response = $client->get('https://httpbin.org/get', [
        'query' => ['hello' => 'world'],
    ]);

    echo 'status: ' . $response->status() . PHP_EOL;
    echo 'successful: ' . var_export($response->successful(), true) . PHP_EOL;
    echo 'url: ' . ($response->array()['url'] ?? '(无)') . PHP_EOL;
} catch (ClientExceptionInterface $e) {
    echo '请求失败: ' . $e->getMessage() . PHP_EOL;
}

echo "\n=== POST JSON 体 ===\n";
try {
    $response = $client->post('https://httpbin.org/post', [
        'json' => ['name' => 'kode', 'active' => true],
    ]);

    echo 'status: ' . $response->status() . PHP_EOL;
    echo 'echo name: ' . ($response->array()['json']['name'] ?? '(无)') . PHP_EOL;
} catch (ClientExceptionInterface $e) {
    echo '请求失败: ' . $e->getMessage() . PHP_EOL;
}

echo "\n=== POST 表单体 ===\n";
try {
    $response = $client->post('https://httpbin.org/post', [
        'form' => ['field' => 'value'],
    ]);

    echo 'status: ' . $response->status() . PHP_EOL;
    echo 'echo field: ' . ($response->array()['form']['field'] ?? '(无)') . PHP_EOL;
} catch (ClientExceptionInterface $e) {
    echo '请求失败: ' . $e->getMessage() . PHP_EOL;
}

echo "\n=== 基础 URI + 相对路径 + 单次超时 ===\n";
$api = Factory::create(['base_uri' => 'https://httpbin.org']);
try {
    $response = $api->get('/headers', ['timeout' => 5.0]);
    echo 'status: ' . $response->status() . PHP_EOL;
} catch (ClientExceptionInterface $e) {
    echo '请求失败: ' . $e->getMessage() . PHP_EOL;
}
