<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Message\MessageFactory;
use Kode\HttpClient\Request\RequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 请求构建器测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class RequestBuilderTest extends TestCase
{
    public function testMethodIsNormalizedToUpperCase(): void
    {
        $request = RequestBuilder::build('post', 'https://example.com');

        self::assertSame('POST', $request->getMethod());
    }

    public function testEmptyMethodIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::build('   ', 'https://example.com');
    }

    public function testQueryMergesWithExistingQueryString(): void
    {
        $request = RequestBuilder::build('GET', 'https://example.com/s?a=1', ['query' => ['b' => 2]]);

        self::assertSame('a=1&b=2', $request->getUri()->getQuery());
    }

    public function testQueryAcceptsRawString(): void
    {
        $request = RequestBuilder::build('GET', 'https://example.com/s', ['query' => '?a=1&b=2']);

        self::assertSame('a=1&b=2', $request->getUri()->getQuery());
    }

    public function testQueryRejectsInvalidType(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::build('GET', 'https://example.com', ['query' => 42]);
    }

    public function testJsonBodyKeepsUnicode(): void
    {
        $request = RequestBuilder::build('POST', 'https://example.com', ['json' => ['msg' => '你好/世界']]);

        self::assertSame('{"msg":"你好/世界"}', (string) $request->getBody());
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
    }

    public function testJsonRejectsUnencodableValue(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::build('POST', 'https://example.com', ['json' => fopen('php://memory', 'rb')]);
    }

    public function testFormRejectsNonArray(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::build('POST', 'https://example.com', ['form' => 'a=1']);
    }

    public function testRawStringBody(): void
    {
        $request = RequestBuilder::build('PUT', 'https://example.com', ['body' => 'raw-payload']);

        self::assertSame('raw-payload', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    public function testStreamBody(): void
    {
        $stream = MessageFactory::createStream('stream-payload');
        $request = RequestBuilder::build('PUT', 'https://example.com', ['body' => $stream]);

        self::assertSame('stream-payload', (string) $request->getBody());
    }

    public function testBodyRejectsUnsupportedType(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::build('PUT', 'https://example.com', ['body' => ['a' => 1]]);
    }

    public function testHeadersOverrideContentType(): void
    {
        $request = RequestBuilder::build('POST', 'https://example.com', [
            'json' => ['a' => 1],
            'headers' => ['Content-Type' => 'application/vnd.api+json'],
        ]);

        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Content-Type'));
    }

    public function testProtocolVersionOption(): void
    {
        $request = RequestBuilder::build('GET', 'https://example.com', ['version' => '2']);

        self::assertSame('2', $request->getProtocolVersion());
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::assertOptions(['nope' => 1]);
    }

    public function testMutuallyExclusiveBodyOptionsAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        RequestBuilder::assertOptions(['json' => [], 'body' => 'x']);
    }
}
