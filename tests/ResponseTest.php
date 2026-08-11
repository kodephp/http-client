<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Exception\ResponseFormatException;
use Kode\HttpClient\Message\MessageFactory;
use Kode\HttpClient\Response\HttpResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * 响应装饰器与传输配置测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ResponseTest extends TestCase
{
    public function testWrapDoesNotDoubleWrap(): void
    {
        $wrapped = HttpResponse::wrap(new Response(200));

        self::assertSame($wrapped, HttpResponse::wrap($wrapped));
        self::assertInstanceOf(ResponseInterface::class, $wrapped->unwrap());
    }

    public function testTextIsBufferedAndRepeatable(): void
    {
        $response = HttpResponse::wrap(new Response(200, [], 'payload'));

        self::assertSame('payload', $response->text());
        self::assertSame('payload', $response->text());
        self::assertSame('payload', (string) $response);
    }

    public function testJsonDecoding(): void
    {
        $response = HttpResponse::wrap(new Response(200, [], '{"a":1,"b":[1,2]}'));

        self::assertSame(['a' => 1, 'b' => [1, 2]], $response->array());
        self::assertSame(1, $response->json()['a']);
        self::assertInstanceOf(\stdClass::class, $response->json(false));
    }

    public function testInvalidJsonThrows(): void
    {
        $response = HttpResponse::wrap(new Response(200, [], 'not-json'));

        $this->expectException(ResponseFormatException::class);
        $response->json();
    }

    public function testScalarJsonIsNotAnArray(): void
    {
        $response = HttpResponse::wrap(new Response(200, [], '42'));

        self::assertSame(42, $response->json());

        $this->expectException(ResponseFormatException::class);
        $response->array();
    }

    public function testStatusHelpers(): void
    {
        self::assertTrue(HttpResponse::wrap(new Response(200))->ok());
        self::assertTrue(HttpResponse::wrap(new Response(204))->successful());
        self::assertTrue(HttpResponse::wrap(new Response(302))->redirect());
        self::assertTrue(HttpResponse::wrap(new Response(404))->clientError());
        self::assertTrue(HttpResponse::wrap(new Response(500))->serverError());
        self::assertTrue(HttpResponse::wrap(new Response(503))->failed());
        self::assertFalse(HttpResponse::wrap(new Response(200))->failed());
        self::assertSame(418, HttpResponse::wrap(new Response(418))->status());
    }

    public function testHeaderAccessors(): void
    {
        $response = HttpResponse::wrap(new Response(200, ['X-Rate' => '10']));

        self::assertSame('10', $response->header('x-rate'));
        self::assertTrue($response->hasHeader('X-Rate'));
        self::assertSame(['10'], $response->getHeader('X-Rate'));
        self::assertArrayHasKey('X-Rate', $response->getHeaders());
    }

    public function testImmutableWithersStayWrapped(): void
    {
        $response = HttpResponse::wrap(new Response(200));

        $modified = $response
            ->withStatus(201)
            ->withHeader('X-A', '1')
            ->withAddedHeader('X-A', '2')
            ->withProtocolVersion('2')
            ->withBody(MessageFactory::createStream('new-body'));

        self::assertInstanceOf(HttpResponse::class, $modified);
        self::assertSame(201, $modified->status());
        self::assertSame('1, 2', $modified->getHeaderLine('X-A'));
        self::assertSame('2', $modified->getProtocolVersion());
        self::assertSame('new-body', $modified->text());
        self::assertSame(200, $response->status(), '原对象不可变');
        self::assertFalse($modified->withoutHeader('X-A')->hasHeader('X-A'));
    }

    public function testTransportOptionsDefaults(): void
    {
        $options = new TransportOptions();

        self::assertSame(30.0, $options->timeout);
        self::assertSame(10.0, $options->connectTimeout);
        self::assertTrue($options->followRedirects);
        self::assertSame(TransportOptions::DEFAULT_USER_AGENT, $options->userAgent);
    }

    public function testTransportOptionsFromArraySupportsBothNamingStyles(): void
    {
        $snake = TransportOptions::fromArray(['connect_timeout' => 1.5, 'max_redirects' => 2]);
        $camel = TransportOptions::fromArray(['connectTimeout' => 1.5, 'maxRedirects' => 2]);

        self::assertSame(1.5, $snake->connectTimeout);
        self::assertSame(2, $snake->maxRedirects);
        self::assertEquals($snake, $camel);
    }

    public function testTransportOptionsWithIsImmutable(): void
    {
        $base = new TransportOptions(timeout: 30.0);
        $derived = $base->with(['timeout' => 5.0]);

        self::assertSame(30.0, $base->timeout);
        self::assertSame(5.0, $derived->timeout);
        self::assertSame(10.0, $derived->connectTimeout, '未覆盖的字段应保留');
    }

    public function testTransportOptionsRejectsNegativeTimeout(): void
    {
        $this->expectException(ConfigurationException::class);
        new TransportOptions(timeout: -1.0);
    }

    public function testTransportOptionsRoundTrip(): void
    {
        $options = new TransportOptions(timeout: 8.0, proxy: 'http://127.0.0.1:1', verify: '/tmp/ca.pem');

        self::assertEquals($options, TransportOptions::fromArray($options->toArray()));
    }
}
