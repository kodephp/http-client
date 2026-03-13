<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * 中间件测试类
 *
 * 测试所有中间件的功能
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::clear();
    }

    protected function tearDown(): void
    {
        Context::clear();
        parent::tearDown();
    }

    public function testAuthMiddlewareBearerToken(): void
    {
        $middleware = AuthMiddleware::bearer('test-token');
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $req): Response => new Response(200);

        $response = $middleware->process($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAuthMiddlewareApiKey(): void
    {
        $middleware = AuthMiddleware::apiKey('test-api-key', 'X-API-Key');
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $req): Response => new Response(200);

        $response = $middleware->process($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRateLimitMiddleware(): void
    {
        $middleware = new RateLimitMiddleware(2, 1);
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $request): Response => new Response(200);

        $response1 = $middleware->process($request, $next);
        $response2 = $middleware->process($request, $next);

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());

        $this->expectException(\RuntimeException::class);
        $middleware->process($request, $next);
    }

    public function testCacheMiddleware(): void
    {
        $middleware = new CacheMiddleware(10);
        $request = new Request('GET', 'https://example.com');

        $callCount = 0;
        $next = function (Request $request) use (&$callCount): Response {
            $callCount++;
            return new Response(200, [], 'Response ' . $callCount);
        };

        $response1 = $middleware->process($request, $next);
        $this->assertEquals(1, $callCount);
        $this->assertEquals('Response 1', (string) $response1->getBody());

        $response2 = $middleware->process($request, $next);
        $this->assertEquals(1, $callCount, '处理器应该只调用一次，第二次应该从缓存返回');
        $this->assertEquals('Response 1', (string) $response2->getBody());

        $stats = $middleware->getCacheStats();
        $this->assertEquals(1, $stats['total']);
        $this->assertEquals(1, $stats['valid']);
        $this->assertEquals(0, $stats['expired']);
    }

    public function testCacheMiddlewareSkipsNonGetRequests(): void
    {
        $middleware = new CacheMiddleware(10);
        $request = new Request('POST', 'https://example.com');

        $callCount = 0;
        $next = function (Request $request) use (&$callCount): Response {
            $callCount++;
            return new Response(200, [], 'Response ' . $callCount);
        };

        $middleware->process($request, $next);
        $middleware->process($request, $next);

        $this->assertEquals(2, $callCount, 'POST 请求不应该被缓存');
    }

    public function testTimeoutMiddleware(): void
    {
        $middleware = new TimeoutMiddleware(5.0);
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $request): Response => new Response(200);

        $response = $middleware->process($request, $next);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetryMiddleware(): void
    {
        $middleware = new RetryMiddleware(3);
        $request = new Request('GET', 'https://example.com');

        $callCount = 0;
        $next = function (Request $request) use (&$callCount): Response {
            $callCount++;
            if ($callCount < 3) {
                throw new \Exception('Temporary failure');
            }
            return new Response(200);
        };

        $response = $middleware->process($request, $next);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(3, $callCount);
    }

    public function testRetryMiddlewareExceedsMaxRetries(): void
    {
        $middleware = new RetryMiddleware(2);
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $request): Response => throw new \Exception('Permanent failure');

        $this->expectException(\Exception::class);
        $middleware->process($request, $next);
    }

    public function testLoggingMiddleware(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $middleware = new LoggingMiddleware($logger);
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $request): Response => new Response(200);

        $response = $middleware->process($request, $next);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(2, $logs, '应该有两条日志：请求和响应');
        $this->assertStringContainsString('HTTP 请求', $logs[0]);
        $this->assertStringContainsString('HTTP 响应', $logs[1]);
    }

    public function testLoggingMiddlewareWithError(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $middleware = new LoggingMiddleware($logger);
        $request = new Request('GET', 'https://example.com');

        $next = fn(Request $request): Response => throw new \Exception('Test error');

        try {
            $middleware->process($request, $next);
            $this->fail('应该抛出异常');
        } catch (\Exception $e) {
            $this->assertEquals('Test error', $e->getMessage());
        }

        $this->assertCount(2, $logs, '应该有两条日志：请求和错误');
        $this->assertStringContainsString('HTTP 请求', $logs[0]);
        $this->assertStringContainsString('HTTP 错误', $logs[1]);
    }
}
