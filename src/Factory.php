<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Driver\AmpDriver;
use Kode\HttpClient\Driver\CurlDriver;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\Driver\SwooleDriver;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 客户端工厂类
 *
 * 自动检测运行环境并创建合适的 HTTP 客户端
 * 支持多种运行时环境（FPM、CLI、Swoole、Swow、Fiber）
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class Factory
{
    /**
     * 创建 HTTP 客户端
     *
     * @param array<string, mixed> $options 配置选项
     *   - timeout: float 默认超时时间（秒）
     *   - retries: int 最大重试次数
     *   - logger: callable 日志记录器
     *   - auth: array 认证配置 ['type' => 'bearer', 'credential' => 'token']
     *   - rate_limit: array 限流配置 ['capacity' => 10, 'rate' => 1]
     *   - cache: bool 是否启用缓存
     * @return HttpClient HTTP 客户端实例
     */
    public static function create(array $options = []): HttpClient
    {
        $driver = self::createDriver();
        $middlewareStack = self::createMiddlewareStack($options);

        return new HttpClient($driver, $middlewareStack);
    }

    /**
     * 创建驱动实例
     *
     * 自动检测运行环境并选择最合适的驱动
     *
     * @return DriverInterface HTTP 驱动实例
     */
    public static function createDriver(): DriverInterface
    {
        if (extension_loaded('swoole') && class_exists(\Swoole\Coroutine::class)) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid > 0) {
                return new SwooleDriver();
            }
        }

        if (class_exists(\Amp\Http\Client\HttpClient::class)) {
            return new AmpDriver();
        }

        return new CurlDriver();
    }

    /**
     * 创建中间件栈
     *
     * @param array<string, mixed> $options 配置选项
     * @return MiddlewareStack 中间件栈
     */
    private static function createMiddlewareStack(array $options): MiddlewareStack
    {
        $stack = new MiddlewareStack();

        if (isset($options['auth'])) {
            $auth = $options['auth'];
            if ($auth['type'] === 'bearer') {
                $stack->add(AuthMiddleware::bearer($auth['credential']));
            } elseif ($auth['type'] === 'api_key') {
                $header = $auth['header'] ?? 'X-API-Key';
                $stack->add(AuthMiddleware::apiKey($auth['credential'], $header));
            }
        }

        if (isset($options['rate_limit'])) {
            $rateLimit = $options['rate_limit'];
            $capacity = $rateLimit['capacity'] ?? 10;
            $rate = $rateLimit['rate'] ?? 1;
            $stack->add(new RateLimitMiddleware($capacity, $rate));
        }

        if (isset($options['cache']) && $options['cache']) {
            $stack->add(new CacheMiddleware());
        }

        $timeout = $options['timeout'] ?? 30.0;
        $stack->add(new TimeoutMiddleware($timeout));

        $retries = $options['retries'] ?? 3;
        if ($retries > 0) {
            $stack->add(new RetryMiddleware($retries));
        }

        if (isset($options['logger'])) {
            $stack->add(new LoggingMiddleware($options['logger']));
        }

        return $stack;
    }

    /**
     * 创建简单的 HTTP 客户端（无中间件）
     *
     * @return HttpClient HTTP 客户端实例
     */
    public static function createSimple(): HttpClient
    {
        return new HttpClient(self::createDriver());
    }

    /**
     * 创建带自定义中间件的 HTTP 客户端
     *
     * @param MiddlewareStack $middlewareStack 中间件栈
     * @return HttpClient HTTP 客户端实例
     */
    public static function createWithMiddleware(MiddlewareStack $middlewareStack): HttpClient
    {
        return new HttpClient(self::createDriver(), $middlewareStack);
    }
}
