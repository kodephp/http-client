<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\HeadersMiddleware;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Response\HttpResponse;
use Kode\HttpClient\Tests\Support\RecordingDriver;
use PHPUnit\Framework\TestCase;

/**
 * HttpClient 测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class HttpClientTest extends TestCase
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

    public function testSendRequestWrapsResponse(): void
    {
        $driver = new RecordingDriver([new Response(200, [], 'hello')]);
        $client = new HttpClient($driver);

        $response = $client->sendRequest(new Request('GET', 'https://example.com'));

        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame('hello', $response->text());
        self::assertTrue($response->successful());
    }

    public function testGetBuildsQueryString(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);

        $client->get('https://example.com/search?tag=a', ['query' => ['q' => 'php 8.3', 'page' => 2]]);

        $uri = (string) $driver->lastRequest()?->getUri();
        self::assertStringContainsString('tag=a', $uri);
        self::assertStringContainsString('q=php%208.3', $uri);
        self::assertStringContainsString('page=2', $uri);
    }

    public function testPostJsonSetsContentType(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);

        $client->post('https://example.com/api', ['json' => ['name' => '张三']]);

        $request = $driver->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"张三"}', (string) $request->getBody());
    }

    public function testPostFormEncodesBody(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);

        $client->post('https://example.com/login', ['form' => ['user' => 'a b', 'pwd' => 'x&y']]);

        $request = $driver->lastRequest();
        self::assertNotNull($request);
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('user=a+b&pwd=x%26y', (string) $request->getBody());
    }

    public function testAllVerbsAreDispatched(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);

        $client->get('https://example.com');
        $client->post('https://example.com');
        $client->put('https://example.com');
        $client->patch('https://example.com');
        $client->delete('https://example.com');
        $client->head('https://example.com');
        $client->options('https://example.com');

        $methods = array_map(static fn($r): string => $r->getMethod(), $driver->requests);
        self::assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], $methods);
    }

    public function testBaseUriResolution(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver, null, 'https://api.example.com/v1/');

        $client->get('users');
        $client->get('/posts');
        $client->get('https://other.example.com/raw');

        $uris = array_map(static fn($r): string => (string) $r->getUri(), $driver->requests);
        self::assertSame([
            'https://api.example.com/v1/users',
            'https://api.example.com/v1/posts',
            'https://other.example.com/raw',
        ], $uris);
    }

    public function testMiddlewareStackIsApplied(): void
    {
        $driver = new RecordingDriver();
        $stack = new MiddlewareStack([new HeadersMiddleware(['X-App' => 'kode'])]);
        $client = new HttpClient($driver, $stack);

        $client->get('https://example.com');

        self::assertSame('kode', $driver->lastRequest()?->getHeaderLine('X-App'));
    }

    public function testScopedTimeoutIsRestored(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);
        $observed = null;

        $spy = new MiddlewareStack([
            new Support\CallbackMiddleware(
                function ($request, callable $next) use (&$observed) {
                    $observed = Context::getTimeout();
                    return $next($request);
                }
            ),
        ]);

        $client->withMiddlewareStack($spy)->get('https://example.com', ['timeout' => 2.5]);

        self::assertSame(2.5, $observed);
        self::assertNull(Context::getTimeout(), '请求结束后上下文必须还原');
    }

    public function testScopedTransportOptions(): void
    {
        $driver = new RecordingDriver();
        $observed = null;

        $spy = new MiddlewareStack([
            new Support\CallbackMiddleware(
                function ($request, callable $next) use (&$observed) {
                    $observed = Context::getTransportOptions();
                    return $next($request);
                }
            ),
        ]);

        (new HttpClient($driver, $spy))->get('https://example.com', [
            'transport' => ['proxy' => 'http://127.0.0.1:7890', 'verify' => false],
            'timeout' => 3.0,
        ]);

        self::assertNotNull($observed);
        self::assertSame('http://127.0.0.1:7890', $observed->proxy);
        self::assertFalse($observed->verify);
        self::assertSame(3.0, $observed->timeout);
        self::assertNull(Context::rawTransportOptions());
    }

    public function testSendConcurrentUsesDriverWhenNoMiddleware(): void
    {
        $driver = new RecordingDriver([
            new Response(200, [], 'a'),
            new Response(201, [], 'b'),
        ]);
        $client = new HttpClient($driver);

        $results = $client->sendConcurrent([
            'first' => new Request('GET', 'https://example.com/a'),
            'second' => new Request('GET', 'https://example.com/b'),
        ]);

        self::assertTrue($driver->concurrentUsed);
        self::assertTrue($client->supportsParallel());
        self::assertSame(['first', 'second'], array_keys($results));
        self::assertInstanceOf(HttpResponse::class, $results['first']);
        self::assertSame('a', $results['first']->text());
        self::assertSame(201, $results['second']->status());
    }

    public function testSendConcurrentSettlesFailures(): void
    {
        $request = new Request('GET', 'https://example.com/bad');
        $driver = new RecordingDriver([
            new Response(200, [], 'ok'),
            new NetworkException('连接失败', $request),
        ]);
        $client = new HttpClient($driver);

        $results = $client->sendConcurrent([$request, $request]);

        self::assertInstanceOf(HttpResponse::class, $results[0]);
        self::assertInstanceOf(NetworkException::class, $results[1]);
    }

    public function testSendConcurrentFallsBackToSequentialWithMiddleware(): void
    {
        $driver = new RecordingDriver([new Response(200), new Response(200)]);
        $client = new HttpClient($driver, new MiddlewareStack([new HeadersMiddleware(['X-App' => 'kode'])]));

        $results = $client->sendConcurrent([
            new Request('GET', 'https://example.com/1'),
            new Request('GET', 'https://example.com/2'),
        ]);

        self::assertFalse($client->supportsParallel());
        self::assertFalse($driver->concurrentUsed);
        self::assertCount(2, $results);
        self::assertSame('kode', $driver->requests[0]->getHeaderLine('X-App'));
    }

    public function testPoolBuildsRequestsAndKeepsKeys(): void
    {
        $driver = new RecordingDriver([
            new Response(200, [], 'one'),
            new Response(200, [], 'two'),
        ]);
        $client = new HttpClient($driver, null, 'https://api.example.com');

        $results = $client->pool([
            'a' => ['GET', 'users/1'],
            'b' => ['GET', 'users/2', ['query' => ['full' => 1]]],
        ]);

        self::assertSame(['a', 'b'], array_keys($results));
        self::assertSame('one', $results['a']->text());
        self::assertStringContainsString('full=1', (string) $driver->requests[1]->getUri());
    }

    public function testPoolCapturesBuildErrors(): void
    {
        $client = new HttpClient(new RecordingDriver());

        $results = $client->pool([
            'bad' => ['GET', 'https://example.com', ['unknown' => 1]],
        ]);

        self::assertInstanceOf(ConfigurationException::class, $results['bad']);
    }

    public function testWithersReturnNewInstances(): void
    {
        $driver = new RecordingDriver();
        $client = new HttpClient($driver);

        $withMiddleware = $client->withMiddleware(new HeadersMiddleware(['X-A' => '1']));
        $withBaseUri = $client->withBaseUri('https://api.example.com');
        $withDriver = $client->withDriver(new RecordingDriver());

        self::assertNotSame($client, $withMiddleware);
        self::assertNotSame($client, $withBaseUri);
        self::assertNotSame($client, $withDriver);
        self::assertNull($client->getMiddlewareStack());
        self::assertCount(1, $withMiddleware->getMiddlewareStack());
        self::assertSame('https://api.example.com', $withBaseUri->getBaseUri());
        self::assertSame($driver, $client->getDriver());
    }

    public function testRequestRejectsUnknownOption(): void
    {
        $client = new HttpClient(new RecordingDriver());

        $this->expectException(ConfigurationException::class);
        $client->get('https://example.com', ['timeoutt' => 1]);
    }
}
