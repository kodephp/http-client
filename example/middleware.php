<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\CircuitBreakerMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件的装配与运行（使用桩驱动，完全离线、确定性）。
 *
 * 运行：php example/middleware.php
 */

/**
 * 桩驱动：回显请求信息，便于离线观察中间件对请求的改写。
 */
final class EchoDriver implements DriverInterface
{
    public ?RequestInterface $last = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;

        return new Response(200, ['X-Echo' => 'yes'], 'ok');
    }
}

$driver = new EchoDriver();
$client = new HttpClient($driver);

echo "=== 1. 日志 + 认证中间件 ===\n";
$logs = [];
$authed = $client->withMiddleware(
    new LoggingMiddleware(function (string $message, string $level = 'info') use (&$logs): void {
        $logs[] = "[$level] $message";
    }),
    AuthMiddleware::bearer('my-token'),
);

$authed->sendRequest(new Request('GET', 'https://api.example.com/me'));
echo '注入的 Authorization: ' . $driver->last->getHeaderLine('Authorization') . PHP_EOL;
echo '日志条数: ' . count($logs) . PHP_EOL;

echo "\n=== 2. 默认请求头中间件 ===\n";
$withHeaders = $client->withMiddleware(
    new \Kode\HttpClient\Middleware\HeadersMiddleware(['Accept' => 'application/json'])
);
$withHeaders->sendRequest(new Request('GET', 'https://api.example.com/x'));
echo 'Accept: ' . $driver->last->getHeaderLine('Accept') . PHP_EOL;

echo "\n=== 3. 自定义中间件 ===\n";

final class RequestIdMiddleware implements \Kode\HttpClient\Middleware\MiddlewareInterface
{
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        return $next($request->withHeader('X-Request-Id', 'fixed-id-123'));
    }
}

$client->withMiddleware(new RequestIdMiddleware())
    ->sendRequest(new Request('GET', 'https://api.example.com/y'));
echo 'X-Request-Id: ' . $driver->last->getHeaderLine('X-Request-Id') . PHP_EOL;

echo "\n=== 4. 中间件栈信息 ===\n";
$stack = $client->withMiddleware(
    new RetryMiddleware(maxRetries: 3, initialBackoff: 10, backoffMultiplier: 2.0),
    new RateLimitMiddleware(capacity: 10, rate: 1.0),
)->getMiddlewareStack();
echo '栈内中间件数量: ' . $stack->count() . PHP_EOL;

echo "\n=== 5. 各中间件运行时状态 ===\n";
$rateLimit = new RateLimitMiddleware(capacity: 10, rate: 1.0);
echo 'RateLimit  capacity=' . $rateLimit->getCapacity() . ' tokens=' . $rateLimit->getTokens() . PHP_EOL;

$cache = new CacheMiddleware(defaultTtl: 60);
echo 'Cache      stats=' . json_encode($cache->getCacheStats(), JSON_UNESCAPED_UNICODE) . PHP_EOL;

$breaker = new CircuitBreakerMiddleware(failureThreshold: 5, resetTimeout: 30.0);
echo 'Breaker    state=' . $breaker->stateOf('api.example.com') . PHP_EOL;

echo "\n示例结束。\n";
