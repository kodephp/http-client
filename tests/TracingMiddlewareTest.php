<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Response;
use Kode\Context\Context as BaseContext;
use Kode\HttpClient\Factory;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Middleware\TracingMiddleware;
use Kode\HttpClient\Tests\Support\RecordingDriver;
use PHPUnit\Framework\TestCase;

/**
 * TracingMiddleware 测试
 *
 * 验证链路上下文（W3C traceparent 与 X-Context-*）能注入出站请求，
 * 以及可选地将下游响应里的上下文回写到当前上下文。
 *
 * @package Kode\HttpClient\Tests
 */
final class TracingMiddlewareTest extends TestCase
{
    private RecordingDriver $driver;

    protected function setUp(): void
    {
        // 预设响应携带下游上下文头，用于测试 propagateResponse
        $this->driver = new RecordingDriver([
            new Response(200, ['X-Context-Correlation-Id' => 'down-123'], 'ok'),
        ]);
    }

    public function testPropagatesW3CAndContextHeadersWhenTraceActive(): void
    {
        BaseContext::run(function (): void {
            BaseContext::startTrace();
            BaseContext::setRequestId('req-1');

            $client = new HttpClient($this->driver, new MiddlewareStack([new TracingMiddleware()]));
            $client->get('https://example.com/api');

            $sent = $this->driver->lastRequest();
            self::assertNotNull($sent);
            self::assertTrue($sent->hasHeader('traceparent'), '活跃链路下应注入 W3C traceparent');
            self::assertSame('req-1', $sent->getHeaderLine('x-context-request-id'));
        });
    }

    public function testNoActiveTraceStillSendsContextHeadersButNoTraceparent(): void
    {
        BaseContext::run(function (): void {
            BaseContext::setRequestId('req-2');

            $client = new HttpClient($this->driver, new MiddlewareStack([new TracingMiddleware()]));
            $client->get('https://example.com');

            $sent = $this->driver->lastRequest();
            self::assertNotNull($sent);
            self::assertTrue($sent->hasHeader('x-context-request-id'));
            self::assertFalse($sent->hasHeader('traceparent'), '无活跃链路时不应注入 W3C traceparent');
        });
    }

    public function testPropagateResponseImportsDownstreamContext(): void
    {
        BaseContext::run(function (): void {
            $client = new HttpClient(
                $this->driver,
                new MiddlewareStack([new TracingMiddleware(propagateResponse: true)])
            );
            $client->get('https://example.com');

            self::assertSame('down-123', BaseContext::get('correlation_id'));
        });
    }

    public function testFactoryEnablesTracingViaConfig(): void
    {
        BaseContext::run(function (): void {
            BaseContext::startTrace();

            $stack = Factory::createMiddlewareStack(['trace' => true]);
            $client = new HttpClient($this->driver, $stack);
            $client->get('https://example.com');

            $sent = $this->driver->lastRequest();
            self::assertNotNull($sent);
            self::assertTrue($sent->hasHeader('traceparent'));
        });
    }
}
