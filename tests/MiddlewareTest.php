<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\Context\Context;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
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
            return new Response(200, [], 'Hello World');
        };
        
        $response = $middleware->process($request, $context, $next);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', (string) $response->getBody());
        $this->assertNotEmpty($logs);
    }
    
    public function testTimeoutMiddleware()
    {
        $middleware = new TimeoutMiddleware(5.0);
        
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $next = function (Request $request, Context $context) {
            // 检查上下文是否包含超时设置
            $this->assertNotNull($context->getTimeout());
            $this->assertEquals(5.0, $context->getTimeout());
            return new Response(200, [], 'Hello World');
        };
        
        $response = $middleware->process($request, $context, $next);
        
        $this->assertEquals(200, $response->getStatusCode());
    }
    
    public function testRetryMiddleware()
    {
        $middleware = new RetryMiddleware(3);
        
        $request = new Request('GET', 'https://example.com');
        $context = new Context();
        
        $attempts = 0;
        $next = function (Request $request, Context $context) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \Exception('Simulated network error');
            }
            return new Response(200, [], 'Success after retries');
        };
        
        $response = $middleware->process($request, $context, $next);
        
        $this->assertEquals(3, $attempts);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Success after retries', (string) $response->getBody());
    }
}