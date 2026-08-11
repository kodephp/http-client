<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\CircuitBreakerOpenException;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RateLimitException;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\CircuitBreakerMiddleware;
use Kode\HttpClient\Middleware\HeadersMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use Kode\HttpClient\Tests\Support\CallbackMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
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

    /**
     * 生成一个「什么都不等待」的睡眠函数，避免测试真的 sleep
     *
     * @param list<float> $recorded 记录容器
     * @return callable(float): void
     */
    private function fakeSleeper(array &$recorded): callable
    {
        return static function (float $seconds) use (&$recorded): void {
            $recorded[] = $seconds;
        };
    }

    public function testAuthMiddlewareBearerToken(): void
    {
        $middleware = AuthMiddleware::bearer('test-token');
        $captured = null;

        $middleware->process(
            new Request('GET', 'https://example.com'),
            function (RequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;
                return new Response(200);
            }
        );

        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('Bearer test-token', $captured->getHeaderLine('Authorization'));
    }

    public function testAuthMiddlewareApiKeyUsesCustomHeader(): void
    {
        $middleware = AuthMiddleware::apiKey('secret', 'X-Token');
        $captured = null;

        $middleware->process(
            new Request('GET', 'https://example.com'),
            function (RequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;
                return new Response(200);
            }
        );

        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('secret', $captured->getHeaderLine('X-Token'));
        self::assertFalse($captured->hasHeader('Authorization'));
    }

    public function testAuthMiddlewareBasic(): void
    {
        $middleware = AuthMiddleware::basic('alice', 'p@ss');
        $captured = null;

        $middleware->process(
            new Request('GET', 'https://example.com'),
            function (RequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;
                return new Response(200);
            }
        );

        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('Basic ' . base64_encode('alice:p@ss'), $captured->getHeaderLine('Authorization'));
    }

    public function testAuthMiddlewareSupportsDynamicCredential(): void
    {
        $counter = 0;
        $middleware = AuthMiddleware::bearer(static function () use (&$counter): string {
            $counter++;
            return 'token-' . $counter;
        });

        $tokens = [];
        $next = function (RequestInterface $req) use (&$tokens): ResponseInterface {
            $tokens[] = $req->getHeaderLine('Authorization');
            return new Response(200);
        };

        $middleware->process(new Request('GET', 'https://example.com'), $next);
        $middleware->process(new Request('GET', 'https://example.com'), $next);

        self::assertSame(['Bearer token-1', 'Bearer token-2'], $tokens);
    }

    public function testAuthMiddlewareRejectsUnknownType(): void
    {
        $this->expectException(ConfigurationException::class);
        new AuthMiddleware('oauth9', 'x');
    }

    public function testRateLimitMiddlewareThrowsWhenBucketDrained(): void
    {
        $middleware = new RateLimitMiddleware(capacity: 2, rate: 1.0);
        $request = new Request('GET', 'https://example.com');
        $next = static fn(RequestInterface $req): ResponseInterface => new Response(200);

        self::assertSame(200, $middleware->process($request, $next)->getStatusCode());
        self::assertSame(200, $middleware->process($request, $next)->getStatusCode());

        $this->expectException(RateLimitException::class);
        $middleware->process($request, $next);
    }

    public function testRateLimitMiddlewareBlockingWaitsInsteadOfThrowing(): void
    {
        $slept = [];
        $middleware = new RateLimitMiddleware(
            capacity: 1,
            rate: 10.0,
            blocking: true,
            maxWait: 5.0,
            sleeper: $this->fakeSleeper($slept)
        );

        $request = new Request('GET', 'https://example.com');
        $next = static fn(RequestInterface $req): ResponseInterface => new Response(200);

        $middleware->process($request, $next);
        $middleware->process($request, $next);

        self::assertCount(1, $slept);
        self::assertGreaterThan(0.0, $slept[0]);
        self::assertSame(1, $middleware->getCapacity());
    }

    public function testRateLimitMiddlewareRejectsInvalidCapacity(): void
    {
        $this->expectException(ConfigurationException::class);
        new RateLimitMiddleware(capacity: 0);
    }

    public function testCacheMiddlewareCachesGetRequests(): void
    {
        $middleware = new CacheMiddleware(defaultTtl: 10);
        $request = new Request('GET', 'https://example.com');

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return new Response(200, [], 'Response ' . $callCount);
        };

        $first = $middleware->process($request, $next);
        $second = $middleware->process($request, $next);

        self::assertSame(1, $callCount, '第二次应命中缓存');
        self::assertSame('Response 1', (string) $first->getBody());
        self::assertSame('Response 1', (string) $second->getBody());
        self::assertSame('HIT', $second->getHeaderLine(CacheMiddleware::CACHE_HEADER));

        $stats = $middleware->getCacheStats();
        self::assertSame(1, $stats['total']);
        self::assertSame(1, $stats['valid']);
        self::assertSame(0, $stats['expired']);
        self::assertSame(1, $stats['hits']);
        self::assertSame(1, $stats['misses']);
    }

    public function testCacheMiddlewareIsolatesByAuthorizationHeader(): void
    {
        $middleware = new CacheMiddleware(defaultTtl: 10);

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return new Response(200, [], 'user-' . $callCount);
        };

        $alice = (new Request('GET', 'https://example.com/me'))->withHeader('Authorization', 'Bearer alice');
        $bob = (new Request('GET', 'https://example.com/me'))->withHeader('Authorization', 'Bearer bob');

        $r1 = $middleware->process($alice, $next);
        $r2 = $middleware->process($bob, $next);

        self::assertSame(2, $callCount, '不同 Authorization 必须使用不同缓存键');
        self::assertSame('user-1', (string) $r1->getBody());
        self::assertSame('user-2', (string) $r2->getBody());
    }

    public function testCacheMiddlewareRespectsNoStore(): void
    {
        $middleware = new CacheMiddleware(defaultTtl: 10);
        $request = new Request('GET', 'https://example.com/nostore');

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return new Response(200, ['Cache-Control' => 'no-store'], 'v' . $callCount);
        };

        $middleware->process($request, $next);
        $middleware->process($request, $next);

        self::assertSame(2, $callCount, 'no-store 响应不得进入缓存');
        self::assertSame(0, $middleware->getCacheStats()['total']);
    }

    public function testCacheMiddlewareEvictsOldestBeyondCapacity(): void
    {
        $middleware = new CacheMiddleware(defaultTtl: 60, maxEntries: 2);
        $next = static fn(RequestInterface $req): ResponseInterface => new Response(200, [], 'body');

        foreach (['a', 'b', 'c'] as $path) {
            $middleware->process(new Request('GET', 'https://example.com/' . $path), $next);
        }

        $stats = $middleware->getCacheStats();
        self::assertSame(2, $stats['total'], 'LRU 容量必须生效');
        self::assertSame(2, $stats['capacity']);
    }

    public function testCacheMiddlewareSkipsNonGetRequests(): void
    {
        $middleware = new CacheMiddleware(defaultTtl: 10);
        $request = new Request('POST', 'https://example.com');

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return new Response(200, [], 'Response ' . $callCount);
        };

        $middleware->process($request, $next);
        $middleware->process($request, $next);

        self::assertSame(2, $callCount, 'POST 请求不应该被缓存');
    }

    public function testTimeoutMiddlewareInjectsAndRestoresContext(): void
    {
        $middleware = new TimeoutMiddleware(5.0, 2.0);
        $observed = null;

        $response = $middleware->process(
            new Request('GET', 'https://example.com'),
            function (RequestInterface $req) use (&$observed): ResponseInterface {
                $observed = Context::getTransportOptions();
                return new Response(200);
            }
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($observed);
        self::assertSame(5.0, $observed->timeout);
        self::assertSame(2.0, $observed->connectTimeout);
        self::assertNull(Context::getTimeout(), '中间件必须还原上下文');
        self::assertNull(Context::rawTransportOptions());
    }

    public function testTimeoutMiddlewarePrefersContextTimeout(): void
    {
        Context::setTimeout(1.25);
        $middleware = new TimeoutMiddleware(30.0);
        $observed = null;

        $middleware->process(
            new Request('GET', 'https://example.com'),
            function (RequestInterface $req) use (&$observed): ResponseInterface {
                $observed = Context::getTransportOptions()->timeout;
                return new Response(200);
            }
        );

        self::assertSame(1.25, $observed);
        self::assertSame(1.25, Context::getTimeout());
    }

    public function testRetryMiddlewareRetriesNetworkErrors(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(maxRetries: 3, sleeper: $this->fakeSleeper($slept));
        $request = new Request('GET', 'https://example.com');

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            if ($callCount < 3) {
                throw new NetworkException('临时故障', $req);
            }
            return new Response(200);
        };

        $response = $middleware->process($request, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $callCount);
        self::assertCount(2, $slept);
        self::assertSame(2, Context::getRetryCount());
    }

    public function testRetryMiddlewareDoesNotRetryGenericExceptions(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(maxRetries: 3, sleeper: $this->fakeSleeper($slept));

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            throw new \LogicException('业务异常不该重试');
        };

        try {
            $middleware->process(new Request('GET', 'https://example.com'), $next);
            self::fail('应抛出异常');
        } catch (\LogicException $e) {
            self::assertSame('业务异常不该重试', $e->getMessage());
        }

        self::assertSame(1, $callCount, '非网络异常不得重试');
        self::assertSame([], $slept);
    }

    public function testRetryMiddlewareSkipsNonIdempotentMethodsByDefault(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(maxRetries: 3, sleeper: $this->fakeSleeper($slept));

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            throw new NetworkException('连接被重置', $req);
        };

        try {
            $middleware->process(new Request('POST', 'https://example.com/orders'), $next);
            self::fail('应抛出异常');
        } catch (NetworkException) {
            // 预期
        }

        self::assertSame(1, $callCount, 'POST 默认不重试，避免重复下单');
    }

    public function testRetryMiddlewareRetriesRetryableStatusCodes(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(maxRetries: 2, sleeper: $this->fakeSleeper($slept));

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return $callCount < 2 ? new Response(503) : new Response(200);
        };

        $response = $middleware->process(new Request('GET', 'https://example.com'), $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $callCount);
    }

    public function testRetryMiddlewareHonoursRetryAfterHeader(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(
            maxRetries: 1,
            maxBackoff: 60000,
            sleeper: $this->fakeSleeper($slept)
        );

        $callCount = 0;
        $next = function (RequestInterface $req) use (&$callCount): ResponseInterface {
            $callCount++;
            return $callCount < 2 ? new Response(429, ['Retry-After' => '3']) : new Response(200);
        };

        $middleware->process(new Request('GET', 'https://example.com'), $next);

        self::assertSame([3.0], $slept);
    }

    public function testRetryMiddlewareCapsBackoff(): void
    {
        $slept = [];
        $middleware = new RetryMiddleware(
            maxRetries: 5,
            initialBackoff: 1000,
            backoffMultiplier: 10.0,
            maxBackoff: 2000,
            sleeper: $this->fakeSleeper($slept)
        );

        $next = static fn(RequestInterface $req): ResponseInterface => new Response(500);
        $middleware->process(new Request('GET', 'https://example.com'), $next);

        self::assertCount(5, $slept);
        foreach ($slept as $seconds) {
            self::assertLessThanOrEqual(2.2, $seconds, '退避必须被 maxBackoff 封顶（含抖动）');
        }
    }

    public function testRetryMiddlewareRejectsNegativeRetries(): void
    {
        $this->expectException(ConfigurationException::class);
        new RetryMiddleware(maxRetries: -1);
    }

    public function testCircuitBreakerOpensAfterThreshold(): void
    {
        $breaker = new CircuitBreakerMiddleware(failureThreshold: 2, resetTimeout: 30.0);
        $request = new Request('GET', 'https://api.example.com/orders');
        $next = static fn(RequestInterface $req): ResponseInterface => new Response(500);

        $breaker->process($request, $next);
        $breaker->process($request, $next);

        self::assertSame(
            CircuitBreakerMiddleware::STATE_OPEN,
            $breaker->stateOf('https://api.example.com')
        );

        $this->expectException(CircuitBreakerOpenException::class);
        $breaker->process($request, $next);
    }

    public function testCircuitBreakerResetRestoresClosedState(): void
    {
        $breaker = new CircuitBreakerMiddleware(failureThreshold: 1);
        $request = new Request('GET', 'https://api.example.com/x');

        $breaker->process($request, static fn(RequestInterface $req): ResponseInterface => new Response(502));
        self::assertSame(CircuitBreakerMiddleware::STATE_OPEN, $breaker->stateOf('https://api.example.com'));

        $breaker->reset();

        self::assertSame(CircuitBreakerMiddleware::STATE_CLOSED, $breaker->stateOf('https://api.example.com'));
        self::assertSame([], $breaker->snapshot());
    }

    public function testHeadersMiddlewareOnlyFillsMissingHeaders(): void
    {
        $middleware = new HeadersMiddleware(['X-App' => 'kode', 'Accept' => 'application/json']);
        $captured = null;

        $middleware->process(
            (new Request('GET', 'https://example.com'))->withHeader('Accept', 'text/plain'),
            function (RequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;
                return new Response(200);
            }
        );

        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('kode', $captured->getHeaderLine('X-App'));
        self::assertSame('text/plain', $captured->getHeaderLine('Accept'), '默认不覆盖已有头');
    }

    public function testHeadersMiddlewareCanOverride(): void
    {
        $middleware = new HeadersMiddleware(['Accept' => 'application/json'], true);
        $captured = null;

        $middleware->process(
            (new Request('GET', 'https://example.com'))->withHeader('Accept', 'text/plain'),
            function (RequestInterface $req) use (&$captured): ResponseInterface {
                $captured = $req;
                return new Response(200);
            }
        );

        self::assertInstanceOf(RequestInterface::class, $captured);
        self::assertSame('application/json', $captured->getHeaderLine('Accept'));
    }

    public function testLoggingMiddlewareLogsRequestAndResponse(): void
    {
        $logs = [];
        $middleware = new LoggingMiddleware(static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        $response = $middleware->process(
            new Request('GET', 'https://example.com'),
            static fn(RequestInterface $req): ResponseInterface => new Response(200)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $logs);
        self::assertStringContainsString('HTTP 请求', $logs[0]);
        self::assertStringContainsString('HTTP 响应', $logs[1]);
    }

    public function testLoggingMiddlewareLogsError(): void
    {
        $logs = [];
        $middleware = new LoggingMiddleware(static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });

        try {
            $middleware->process(
                new Request('GET', 'https://example.com'),
                static fn(RequestInterface $req): ResponseInterface => throw new \RuntimeException('Test error')
            );
            self::fail('应抛出异常');
        } catch (\RuntimeException $e) {
            self::assertSame('Test error', $e->getMessage());
        }

        self::assertCount(2, $logs);
        self::assertStringContainsString('HTTP 错误', $logs[1]);
    }

    public function testMiddlewareStackExecutesInOrder(): void
    {
        $order = [];
        $stack = new MiddlewareStack();
        $stack->add(new HeadersMiddleware(['X-First' => '1']))
            ->add(new CallbackMiddleware(
                function (RequestInterface $request, callable $next) use (&$order): ResponseInterface {
                    $order[] = 'inner-before';
                    $response = $next($request);
                    $order[] = 'inner-after';

                    return $response;
                }
            ));

        $response = $stack->handle(
            new Request('GET', 'https://example.com'),
            static function (RequestInterface $req) use (&$order): ResponseInterface {
                $order[] = 'driver:' . $req->getHeaderLine('X-First');
                return new Response(200);
            }
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['inner-before', 'driver:1', 'inner-after'], $order);
        self::assertCount(2, $stack);
        self::assertFalse($stack->isEmpty());
    }

    public function testMiddlewareStackWithIsImmutable(): void
    {
        $base = new MiddlewareStack([new HeadersMiddleware(['X-A' => '1'])]);
        $derived = $base->with(new HeadersMiddleware(['X-B' => '2']));

        self::assertCount(1, $base);
        self::assertCount(2, $derived);
        self::assertNotSame($base, $derived);
    }

    public function testMiddlewareStackPrependAndClear(): void
    {
        $stack = new MiddlewareStack();
        $stack->add(new HeadersMiddleware(['X-A' => '1']))
            ->prepend(new HeadersMiddleware(['X-B' => '2']));

        self::assertCount(2, $stack);
        self::assertCount(2, iterator_to_array($stack));

        $stack->clear();
        self::assertTrue($stack->isEmpty());
    }
}
