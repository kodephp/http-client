<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Driver\ConcurrentDriverInterface;
use Kode\HttpClient\Driver\CurlDriver;
use Kode\HttpClient\Driver\FiberDriver;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Factory;
use Kode\HttpClient\Middleware\HeadersMiddleware;
use Kode\HttpClient\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;

/**
 * 工厂测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class FactoryTest extends TestCase
{
    public function testCreateReturnsClientWithDetectedDriver(): void
    {
        $client = Factory::create();

        self::assertInstanceOf(ConcurrentDriverInterface::class, $client->getDriver());
        self::assertNotNull($client->getMiddlewareStack());
    }

    public function testExplicitDriverSelection(): void
    {
        self::assertInstanceOf(CurlDriver::class, Factory::createDriver(Factory::DRIVER_CURL));
        self::assertInstanceOf(FiberDriver::class, Factory::createDriver(Factory::DRIVER_FIBER));
    }

    public function testUnknownDriverIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::createDriver('teleport');
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::create(['timeoutt' => 5]);
    }

    public function testTransportOptionsFromFlatConfig(): void
    {
        $transport = Factory::createTransportOptions([
            'timeout' => 12.5,
            'connect_timeout' => 3.0,
            'proxy' => 'http://127.0.0.1:8888',
            'verify' => false,
            'user_agent' => 'MyApp/1.0',
            'headers' => ['X-App' => 'kode'],
        ]);

        self::assertSame(12.5, $transport->timeout);
        self::assertSame(3.0, $transport->connectTimeout);
        self::assertSame('http://127.0.0.1:8888', $transport->proxy);
        self::assertFalse($transport->verify);
        self::assertSame('MyApp/1.0', $transport->userAgent);
        self::assertSame(['X-App' => 'kode'], $transport->defaultHeaders);
    }

    public function testFlatOptionsOverrideTransportBlock(): void
    {
        $transport = Factory::createTransportOptions([
            'transport' => new TransportOptions(timeout: 60.0, maxRedirects: 9),
            'timeout' => 5.0,
        ]);

        self::assertSame(5.0, $transport->timeout);
        self::assertSame(9, $transport->maxRedirects);
    }

    public function testInvalidTransportOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::createTransportOptions(['transport' => 'nope']);
    }

    public function testMiddlewareStackComposition(): void
    {
        $stack = Factory::createMiddlewareStack([
            'logger' => static fn(string $m): null => null,
            'circuit_breaker' => true,
            'retries' => 2,
            'cache' => ['ttl' => 30],
            'rate_limit' => ['capacity' => 5, 'rate' => 5],
            'auth' => ['type' => 'bearer', 'credential' => 'tok'],
            'headers' => ['X-App' => 'kode'],
            'middleware' => [new HeadersMiddleware(['X-Extra' => '1'])],
        ]);

        // 日志 + 熔断 + 重试 + 缓存 + 限流 + 认证 + 默认头 + 超时 + 自定义
        self::assertCount(9, $stack);
        self::assertInstanceOf(MiddlewareStack::class, $stack);
    }

    public function testRetriesZeroDisablesRetryMiddleware(): void
    {
        $stack = Factory::createMiddlewareStack(['retries' => 0]);

        // 仅剩超时中间件
        self::assertCount(1, $stack);
    }

    public function testBasicAuthConfiguration(): void
    {
        $stack = Factory::createMiddlewareStack([
            'retries' => 0,
            'auth' => ['type' => 'basic', 'username' => 'u', 'password' => 'p'],
        ]);

        self::assertCount(2, $stack);
    }

    public function testUnknownAuthTypeIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::createMiddlewareStack(['auth' => ['type' => 'kerberos']]);
    }

    public function testAuthWithoutCredentialIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::createMiddlewareStack(['auth' => ['type' => 'bearer']]);
    }

    public function testCustomMiddlewareMustImplementInterface(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::createMiddlewareStack(['middleware' => [new \stdClass()]]);
    }

    public function testCreateSimpleHasNoMiddleware(): void
    {
        // 自 2.5.1 起 createSimple 已改为 create 的别名并标记废弃，
        // 不再是“无中间件”极简客户端；历史静默割裂已修复，重试/熔断/限流配置现在生效。
        $client = Factory::createSimple(['base_uri' => 'https://api.example.com']);

        self::assertNotNull($client->getMiddlewareStack());
        self::assertSame('https://api.example.com', $client->getBaseUri());
        // 由于默认会装配重试+超时中间件，supportsParallel() 为 false；外呼要重试/熔断必须用 Factory::create()
        self::assertFalse($client->supportsParallel());
    }

    public function testCreateSimpleIsDeprecatedAliasAndMiddlewareOptionsTakeEffect(): void
    {
        // 验证静默割裂已修复：retry/circuit_breaker/rate_limit 不再被丢弃
        $client = @Factory::createSimple([
            'retries' => 2,
            'circuit_breaker' => ['failure_threshold' => 3],
            'rate_limit' => ['capacity' => 5, 'rate' => 5],
            'base_uri' => 'https://api.example.com',
        ]);

        $stack = $client->getMiddlewareStack();
        self::assertNotNull($stack);
        // 重试 + 熔断 + 限流 + 超时（至少 4 个），且与 Factory::create 行为一致
        self::assertGreaterThanOrEqual(4, count($stack));

        $reference = Factory::create([
            'retries' => 2,
            'circuit_breaker' => ['failure_threshold' => 3],
            'rate_limit' => ['capacity' => 5, 'rate' => 5],
            'base_uri' => 'https://api.example.com',
        ]);
        self::assertCount(count($reference->getMiddlewareStack()), $stack);
    }

    public function testCreateWithMiddleware(): void
    {
        $stack = new MiddlewareStack([new HeadersMiddleware(['X-A' => '1'])]);
        $client = Factory::createWithMiddleware($stack, Factory::DRIVER_CURL, ['timeout' => 2.0]);

        self::assertSame($stack, $client->getMiddlewareStack());
        self::assertInstanceOf(CurlDriver::class, $client->getDriver());
    }

    public function testAvailableDriversReportsCurrentEnvironment(): void
    {
        $drivers = Factory::availableDrivers();

        self::assertArrayHasKey(Factory::DRIVER_CURL, $drivers);
        self::assertArrayHasKey(Factory::DRIVER_SWOW, $drivers);
        self::assertSame(extension_loaded('curl'), $drivers[Factory::DRIVER_CURL]);
        if (!extension_loaded('swow')) {
            self::assertFalse($drivers[Factory::DRIVER_SWOW]);
        }

        foreach ($drivers as $name => $supported) {
            self::assertIsBool($supported, $name . ' 的可用性必须是布尔值');
        }
    }
}
