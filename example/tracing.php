<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\Context\Context as BaseContext;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\TracingMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 链路追踪中间件（离线演示）。
 * TracingMiddleware 会把 kode/context 的 W3C traceparent 与 X-Context-* 头
 * 自动注入出站请求；无活跃 trace 时安全降级。
 *
 * 运行：php example/tracing.php
 */

final class CapturingDriver implements DriverInterface
{
    public ?RequestInterface $last = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;

        return new Response(200, [], 'ok');
    }
}

$driver = new CapturingDriver();
$client = (new HttpClient($driver))->withMiddleware(new TracingMiddleware());

echo "=== 活跃 trace 上下文中发起请求 ===\n";
BaseContext::run(function () use ($client, $driver): void {
    $traceId = BaseContext::startTrace();
    BaseContext::setRequestId('req-abc-123');

    echo "本地 traceId: $traceId\n";

    $client->sendRequest(new Request('GET', 'https://api.example.com/x'));

    foreach (['traceparent', 'X-Context-Request-Id'] as $name) {
        echo "  注入 $name: " . ($driver->last->getHeaderLine($name) ?: '(无)') . PHP_EOL;
    }
});

echo "\n=== 无活跃 trace（安全降级）===\n";
$client->sendRequest(new Request('GET', 'https://api.example.com/y'));
echo '  traceparent: ' . ($driver->last->getHeaderLine('traceparent') ?: '(无)') . PHP_EOL;
