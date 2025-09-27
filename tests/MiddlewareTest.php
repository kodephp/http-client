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

class MiddlewareTest extends TestCase
{
    public function testAuthMiddlewareBearerToken()
    {
        $middleware = AuthMiddleware::bearer('test-token');
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $next = function (Request $request, Context $context) {
            $this->assertEquals('Bearer test-token', $request->getHeaderLine('Authorization'));
            return new Response(200);
        };
        
        $response = $middleware->process($request, $context, $next);
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    public function testAuthMiddlewareApiKey()
    {
        $middleware = AuthMiddleware::apiKey('test-api-key', 'X-API-Key');
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $next = function (Request $request, Context $context) {
            $this->assertEquals('test-api-key', $request->getHeaderLine('X-API-Key'));
            return new Response(200);
        };
        
        $response = $middleware->process($request, $context, $next);
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    public function testRateLimitMiddleware()
    {
        $middleware = new RateLimitMiddleware(2, 1); // 容量2，速率1个/秒
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $callCount = 0;
        $next = function (Request $request, Context $context) use (&$callCount) {
            $callCount++;
            return new Response(200);
        };
        
        // 前两次应该成功
        $response1 = $middleware->process($request, $context, $next);
        $response2 = $middleware->process($request, $context, $next);
        
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals(2, $callCount);
        
        // 第三次应该抛出异常（因为我们没有等待）
        $this->expectException(\RuntimeException::class);
        $middleware->process($request, $context, $next);
    }
    
    public function testCacheMiddleware()
    {
        $middleware = new CacheMiddleware(10); // 10秒缓存
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $callCount = 0;
        $next = function (Request $request, Context $context) use (&$callCount) {
            $callCount++;
            return new Response(200, [], 'Response ' . $callCount);
        };
        
        // 第一次调用应该执行处理器
        $response1 = $middleware->process($request, $context, $next);
        $this->assertEquals(1, $callCount);
        $this->assertEquals('Response 1', (string) $response1->getBody());
        
        // 第二次调用应该从缓存返回
        $response2 = $middleware->process($request, $context, $next);
        $this->assertEquals(1, $callCount); // 处理器调用次数仍为1
        $this->assertEquals('Response 1', (string) $response2->getBody());
        
        // 检查缓存统计
        $stats = $middleware->getCacheStats();
        $this->assertEquals(1, $stats['total']);
        $this->assertEquals(1, $stats['valid']);
        $this->assertEquals(0, $stats['expired']);
    }
    
    public function testTimeoutMiddleware()
    {
        $middleware = new TimeoutMiddleware(5.0);
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $next = function (Request $request, Context $context) {
            // 简化测试，只验证中间件是否正常执行
            return new Response(200);
        };
        
        $response = $middleware->process($request, $context, $next);
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    public function testRetryMiddleware()
    {
        $middleware = new RetryMiddleware(3); // 最多重试3次
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $callCount = 0;
        $next = function (Request $request, Context $context) use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \Exception('Temporary failure');
            }
            return new Response(200);
        };
        
        $response = $middleware->process($request, $context, $next);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(3, $callCount); // 应该重试2次，总共调用3次
    }
    
    public function testLoggingMiddleware()
    {
        $logs = [];
        $logger = function (string $message) use (&$logs) {
            $logs[] = $message;
        };
        
        $middleware = new LoggingMiddleware($logger);
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $next = function (Request $request, Context $context) {
            return new Response(200);
        };
        
        $response = $middleware->process($request, $context, $next);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('HTTP Request: GET https://example.com', $logs[0]);
    }
}