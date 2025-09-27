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
use Kode\HttpClient\Context\Context;

/**
 * HTTP 客户端工厂类
 *
 * 自动检测运行环境并创建合适的 HTTP 客户端
 */
class Factory
{
    /**
     * 创建 HTTP 客户端
     *
     * @param array $options 配置选项
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
        $client = new HttpClient(self::createDriver());
        
        // 创建中间件栈
        $middlewareStack = new MiddlewareStack();
        
        // 添加认证中间件
        if (isset($options['auth'])) {
            $auth = $options['auth'];
            if ($auth['type'] === 'bearer') {
                $middlewareStack->add(AuthMiddleware::bearer($auth['credential']));
            } elseif ($auth['type'] === 'api_key') {
                $header = $auth['header'] ?? 'X-API-Key';
                $middlewareStack->add(AuthMiddleware::apiKey($auth['credential'], $header));
            }
        }
        
        // 添加限流中间件
        if (isset($options['rate_limit'])) {
            $rateLimit = $options['rate_limit'];
            $capacity = $rateLimit['capacity'] ?? 10;
            $rate = $rateLimit['rate'] ?? 1;
            $middlewareStack->add(new RateLimitMiddleware($capacity, $rate));
        }
        
        // 添加缓存中间件
        if (isset($options['cache']) && $options['cache']) {
            $middlewareStack->add(new CacheMiddleware());
        }
        
        // 添加超时中间件
        $timeout = $options['timeout'] ?? 30.0;
        $middlewareStack->add(new TimeoutMiddleware($timeout));
        
        // 添加重试中间件
        $retries = $options['retries'] ?? 3;
        if ($retries > 0) {
            $middlewareStack->add(new RetryMiddleware($retries));
        }
        
        // 添加日志中间件
        if (isset($options['logger'])) {
            $middlewareStack->add(new LoggingMiddleware($options['logger']));
        }
        
        // 将中间件栈包装到客户端中
        return new class($client, $middlewareStack) extends HttpClient {
            private MiddlewareStack $middlewareStack;

            public function __construct(HttpClient $client, MiddlewareStack $middlewareStack)
            {
                parent::__construct($client->getDriver());
                $this->middlewareStack = $middlewareStack;
            }
            
            public function sendRequest(RequestInterface $request, ?Context $context = null): ResponseInterface
            {
                $context = $context ?? new Context();
                
                return $this->middlewareStack->handle(
                    $request,
                    $context,
                    fn(RequestInterface $req, Context $ctx) => parent::sendRequest($req, $ctx)
                );
            }
        };
    }

    /**
     * 创建驱动
     *
     * @return DriverInterface HTTP 驱动实例
     */
    private static function createDriver(): DriverInterface
    {
        // 检查 Swoole 是否可用
        if (extension_loaded('swoole') && class_exists(\Swoole\Coroutine::class)) {
            // 检查是否在协程环境中
            if (\Swoole\Coroutine::getCid() > 0) {
                // 如果有 Swoole 协程支持，使用 Swoole 驱动
                if (class_exists(SwooleDriver::class)) {
                    return new SwooleDriver();
                }
            }
        }

        // 检查 Amp 是否可用
        if (class_exists(\Amp\Http\Client\HttpClient::class)) {
            return new AmpDriver();
        }

        // 默认使用 Curl 驱动
        return new CurlDriver();
    }
}