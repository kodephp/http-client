<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use Kode\HttpClient\Factory;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Message\MessageFactory;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\HeadersMiddleware;
use Kode\HttpClient\Middleware\MiddlewareInterface;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Tests\Support\RecordingDriver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * 中间件装配顺序回归测试
 *
 * 顺序即语义：缓存键按 Vary 头（含 Authorization）计算，因此缓存必须挂在认证内侧，
 * 否则「上一条请求算键时还没带身份头」会让不同用户命中同一份缓存。
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MiddlewareOrderingTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     * @return list<class-string<MiddlewareInterface>>
     */
    private function stackOrder(array $options): array
    {
        return array_map(
            static fn (MiddlewareInterface $m): string => $m::class,
            Factory::createMiddlewareStack($options)->all(),
        );
    }

    public function testCacheSitsInsideAuthAndHeaders(): void
    {
        $order = $this->stackOrder([
            'auth' => ['type' => 'bearer', 'credential' => 'tok'],
            'headers' => ['X-App' => 'kode'],
            'cache' => ['ttl' => 30],
        ]);

        $index = static fn (string $class): int => array_search($class, $order, true);

        self::assertLessThan($index(CacheMiddleware::class), $index(AuthMiddleware::class), '认证必须先于缓存');
        self::assertLessThan($index(CacheMiddleware::class), $index(HeadersMiddleware::class), '默认头必须先于缓存');
    }

    public function testRateLimitSitsInsideCache(): void
    {
        $order = $this->stackOrder([
            'cache' => ['ttl' => 30],
            'rate_limit' => ['capacity' => 5, 'rate' => 5],
        ]);

        self::assertLessThan(
            array_search(RateLimitMiddleware::class, $order, true),
            array_search(CacheMiddleware::class, $order, true),
            '命中缓存的请求不该消耗限流预算'
        );
    }

    public function testCachedResponsesNeverCrossAuthenticatedIdentities(): void
    {
        $tokens = ['alice' => 'tok-alice', 'bob' => 'tok-bob'];
        $who = 'alice';

        $driver = new RecordingDriver([
            MessageFactory::createResponse(200, [], 'alice-view'),
            MessageFactory::createResponse(200, [], 'bob-view'),
        ]);
        $client = new HttpClient(
            $driver,
            Factory::createMiddlewareStack([
                'cache' => ['ttl' => 300],
                'retries' => 0,
                // 必须按引用捕获：箭头函数会冻结 $who 的取值，测试就成了「永远同一身份」的假绿
                'auth' => ['type' => 'bearer', 'credential' => function () use (&$who, $tokens): string {
                    return $tokens[$who];
                }],
            ])
        );

        self::assertSame('alice-view', (string) $client->get('https://example.com/me')->getBody());

        $who = 'bob';
        $bobBody = (string) $client->get('https://example.com/me')->getBody();

        self::assertSame('bob-view', $bobBody, '换身份后不得复用他人的缓存条目');
        self::assertCount(2, $driver->requests, '两次请求都应真正外呼');

        $seen = array_map(
            static fn (RequestInterface $r): string => $r->getHeaderLine('Authorization'),
            $driver->requests
        );
        self::assertSame(['Bearer tok-alice', 'Bearer tok-bob'], $seen);
    }

    public function testSameIdentityStillHitsCache(): void
    {
        $driver = new RecordingDriver([
            MessageFactory::createResponse(200, [], 'first'),
            MessageFactory::createResponse(200, [], 'second'),
        ]);
        $client = new HttpClient(
            $driver,
            Factory::createMiddlewareStack([
                'cache' => ['ttl' => 300],
                'retries' => 0,
                'auth' => ['type' => 'bearer', 'credential' => 'stable-token'],
            ])
        );

        self::assertSame('first', (string) $client->get('https://example.com/me')->getBody());
        self::assertSame('first', (string) $client->get('https://example.com/me')->getBody());
        self::assertCount(1, $driver->requests, '同一身份应命中缓存而不是外呼两次');
    }

    public function testPlainFactoryClientIsSequentialBecauseOfRetry(): void
    {
        // 默认 retries=3 → 栈非空 → 逐条派发；这是「中间件与并发批量不可兼得」的既有权衡
        self::assertFalse(Factory::create(['retries' => 3])->supportsParallel());
    }

    public function testFactoryClientWithoutMiddlewareSendsConcurrently(): void
    {
        // v2.6.0 起工厂不再默认挂恒等的超时中间件：关掉重试即具备真并发
        self::assertTrue(Factory::create(['retries' => 0])->supportsParallel());
    }
}
