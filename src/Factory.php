<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Driver\AmpDriver;
use Kode\HttpClient\Driver\CurlDriver;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\Driver\FiberDriver;
use Kode\HttpClient\Driver\SwooleDriver;
use Kode\HttpClient\Driver\SwowDriver;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Message\MessageFactory;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\CacheMiddleware;
use Kode\HttpClient\Middleware\CircuitBreakerMiddleware;
use Kode\HttpClient\Middleware\HeadersMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\MiddlewareInterface;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Middleware\RateLimitMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Middleware\TimeoutMiddleware;

/**
 * HTTP 客户端工厂类
 *
 * 自动检测运行环境并创建合适的 HTTP 客户端，
 * 同时把扁平的配置数组翻译成驱动 + 中间件栈的组合。
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class Factory
{
    /**
     * 驱动类型常量
     */
    public const string DRIVER_AUTO = 'auto';
    public const string DRIVER_CURL = 'curl';
    public const string DRIVER_SWOOLE = 'swoole';
    public const string DRIVER_SWOW = 'swow';
    public const string DRIVER_AMP = 'amp';
    public const string DRIVER_FIBER = 'fiber';

    /**
     * 支持的顶层配置键
     *
     * @var list<string>
     */
    public const array SUPPORTED_OPTIONS = [
        'driver',
        'base_uri',
        'transport',
        'timeout',
        'connect_timeout',
        'follow_redirects',
        'max_redirects',
        'verify',
        'proxy',
        'decode_content',
        'user_agent',
        'headers',
        'curl_options',
        'retries',
        'retry',
        'cache',
        'rate_limit',
        'circuit_breaker',
        'auth',
        'logger',
        'middleware',
        'psr17',
    ];

    /**
     * 私有构造函数，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 创建 HTTP 客户端
     *
     * 支持的配置项：
     *  - driver           string  驱动类型（auto|curl|fiber|swoole|swow|amp）
     *  - base_uri         string  基础 URI，相对路径基于它解析
     *  - transport        array|TransportOptions  传输层配置整体覆盖
     *  - timeout          float   总超时（秒）
     *  - connect_timeout  float   连接超时（秒）
     *  - follow_redirects bool    是否跟随重定向
     *  - max_redirects    int     最大重定向次数
     *  - verify           bool|string  TLS 校验策略
     *  - proxy            string  代理地址
     *  - decode_content   bool    是否自动解压响应体
     *  - user_agent       string  默认 User-Agent
     *  - headers          array   默认请求头
     *  - curl_options     array   透传的额外 curl 选项
     *  - retries          int     最大重试次数（0 表示关闭）
     *  - retry            array   重试细节：max_retries、initial_backoff(ms)、backoff_multiplier、
     *                             max_backoff(ms)、status_codes、retry_non_idempotent、respect_retry_after
     *  - cache            bool|array  响应缓存
     *  - rate_limit       array   限流配置 ['capacity' => 10, 'rate' => 1]
     *  - circuit_breaker  bool|array  熔断配置
     *  - auth             array   认证配置 ['type' => 'bearer', 'credential' => 'token']
     *  - logger           callable|object  日志记录器
     *  - middleware       iterable<MiddlewareInterface>  自定义中间件
     *  - psr17            object  自定义 PSR-17 工厂
     *
     * @param array<string, mixed> $options 配置选项
     *
     * @throws ConfigurationException 当配置项非法时抛出
     */
    public static function create(array $options = []): HttpClient
    {
        self::assertOptions($options);

        if (isset($options['psr17'])) {
            if (!is_object($options['psr17'])) {
                throw new ConfigurationException('psr17 选项必须是实现了 PSR-17 工厂接口的对象');
            }
            MessageFactory::register($options['psr17']);
        }

        $transport = self::createTransportOptions($options);
        $driver = self::createDriver(
            is_string($options['driver'] ?? null) ? $options['driver'] : self::DRIVER_AUTO,
            $transport
        );

        $baseUri = isset($options['base_uri']) ? (string) $options['base_uri'] : null;

        return new HttpClient($driver, self::createMiddlewareStack($options), $baseUri);
    }

    /**
     * 从配置数组构建传输层配置
     *
     * @param array<string, mixed> $options 配置选项
     *
     * @throws ConfigurationException 当配置项非法时抛出
     */
    public static function createTransportOptions(array $options): TransportOptions
    {
        $base = [];

        if (isset($options['transport'])) {
            $transport = $options['transport'];

            if ($transport instanceof TransportOptions) {
                $base = $transport->toArray();
            } elseif (is_array($transport)) {
                $base = $transport;
            } else {
                throw new ConfigurationException('transport 选项必须是数组或 TransportOptions 实例');
            }
        }

        $inline = array_intersect_key($options, array_flip([
            'timeout',
            'connect_timeout',
            'follow_redirects',
            'max_redirects',
            'verify',
            'proxy',
            'decode_content',
            'user_agent',
            'curl_options',
        ]));

        if (isset($options['headers']) && is_array($options['headers'])) {
            $inline['default_headers'] = $options['headers'];
        }

        return TransportOptions::fromArray($inline + $base);
    }

    /**
     * 创建驱动实例
     *
     * @param string $preferredDriver 首选驱动类型
     * @param TransportOptions|null $transport 驱动级默认传输配置
     *
     * @throws ConfigurationException 当驱动类型未知或当前环境不支持时抛出
     */
    public static function createDriver(
        string $preferredDriver = self::DRIVER_AUTO,
        ?TransportOptions $transport = null
    ): DriverInterface {
        if ($preferredDriver === self::DRIVER_AUTO) {
            return self::detectDriver($transport);
        }

        $driver = match ($preferredDriver) {
            self::DRIVER_CURL => new CurlDriver($transport),
            self::DRIVER_FIBER => new FiberDriver($transport),
            self::DRIVER_SWOOLE => new SwooleDriver($transport),
            self::DRIVER_SWOW => new SwowDriver($transport),
            self::DRIVER_AMP => new AmpDriver($transport),
            default => throw new ConfigurationException(sprintf(
                '未知的驱动类型 "%s"，可用值：%s',
                $preferredDriver,
                implode(', ', [
                    self::DRIVER_AUTO,
                    self::DRIVER_CURL,
                    self::DRIVER_FIBER,
                    self::DRIVER_SWOOLE,
                    self::DRIVER_SWOW,
                    self::DRIVER_AMP,
                ])
            )),
        };

        return $driver;
    }

    /**
     * 自动检测当前运行时最合适的驱动
     *
     * 优先使用运行时原生的协程驱动，避免阻塞事件循环；
     * 其余情况回落到 curl（自身通过 curl_multi 支持并发）。
     *
     * @param TransportOptions|null $transport 驱动级默认传输配置
     */
    public static function detectDriver(?TransportOptions $transport = null): DriverInterface
    {
        if (SwooleDriver::isSupported()) {
            return new SwooleDriver($transport);
        }

        if (SwowDriver::isSupported()) {
            return new SwowDriver($transport);
        }

        if (CurlDriver::isSupported()) {
            return \Fiber::getCurrent() !== null
                ? new FiberDriver($transport)
                : new CurlDriver($transport);
        }

        if (AmpDriver::isSupported()) {
            return new AmpDriver($transport);
        }

        return new CurlDriver($transport);
    }

    /**
     * 创建中间件栈
     *
     * 顺序（由外到内）：日志 → 熔断 → 重试 → 缓存 → 限流 → 认证 → 默认头 → 超时 → 驱动
     *
     * @param array<string, mixed> $options 配置选项
     *
     * @throws ConfigurationException 当中间件配置非法时抛出
     */
    public static function createMiddlewareStack(array $options): MiddlewareStack
    {
        $stack = new MiddlewareStack();

        if (isset($options['logger'])) {
            $logger = $options['logger'];
            if (!is_callable($logger) && !is_object($logger)) {
                throw new ConfigurationException('logger 选项必须是 callable 或 PSR-3 LoggerInterface 实例');
            }
            $stack->add(new LoggingMiddleware($logger));
        }

        if (!empty($options['circuit_breaker'])) {
            /** @var array<string, mixed> $config */
            $config = is_array($options['circuit_breaker']) ? $options['circuit_breaker'] : [];
            $stack->add(new CircuitBreakerMiddleware(
                failureThreshold: (int) ($config['failure_threshold'] ?? 5),
                resetTimeout: (float) ($config['reset_timeout'] ?? 30.0),
                successThreshold: (int) ($config['success_threshold'] ?? 1),
            ));
        }

        $retries = (int) ($options['retries'] ?? 3);
        /** @var array<string, mixed> $retryConfig */
        $retryConfig = is_array($options['retry'] ?? null) ? $options['retry'] : [];
        if (isset($retryConfig['max_retries'])) {
            $retries = (int) $retryConfig['max_retries'];
        }

        if ($retries > 0) {
            $stack->add(new RetryMiddleware(
                maxRetries: $retries,
                initialBackoff: (int) ($retryConfig['initial_backoff'] ?? 100),
                backoffMultiplier: (float) ($retryConfig['backoff_multiplier'] ?? 2.0),
                maxBackoff: (int) ($retryConfig['max_backoff'] ?? 10000),
                retryStatusCodes: is_array($retryConfig['status_codes'] ?? null)
                    ? array_map(intval(...), $retryConfig['status_codes'])
                    : RetryMiddleware::DEFAULT_RETRY_STATUS_CODES,
                retryNonIdempotent: (bool) ($retryConfig['retry_non_idempotent'] ?? false),
                respectRetryAfter: (bool) ($retryConfig['respect_retry_after'] ?? true),
            ));
        }

        if (!empty($options['cache'])) {
            /** @var array<string, mixed> $config */
            $config = is_array($options['cache']) ? $options['cache'] : [];
            $stack->add(new CacheMiddleware(
                defaultTtl: (int) ($config['ttl'] ?? 300),
                maxEntries: (int) ($config['max_entries'] ?? 256),
                varyHeaders: is_array($config['vary'] ?? null)
                    ? array_map(strval(...), $config['vary'])
                    : CacheMiddleware::DEFAULT_VARY_HEADERS,
                respectCacheControl: (bool) ($config['respect_cache_control'] ?? true),
            ));
        }

        if (isset($options['rate_limit'])) {
            if (!is_array($options['rate_limit'])) {
                throw new ConfigurationException('rate_limit 选项必须是数组');
            }
            /** @var array<string, mixed> $config */
            $config = $options['rate_limit'];
            $stack->add(new RateLimitMiddleware(
                capacity: (int) ($config['capacity'] ?? 10),
                rate: (float) ($config['rate'] ?? 1.0),
                blocking: (bool) ($config['blocking'] ?? true),
                maxWait: (float) ($config['max_wait'] ?? 5.0),
            ));
        }

        if (isset($options['auth'])) {
            $stack->add(self::createAuthMiddleware($options['auth']));
        }

        if (isset($options['headers']) && is_array($options['headers']) && $options['headers'] !== []) {
            /** @var array<string, string> $headers */
            $headers = $options['headers'];
            $stack->add(new HeadersMiddleware($headers));
        }

        $transport = self::createTransportOptions($options);
        $stack->add(new TimeoutMiddleware($transport->timeout, $transport->connectTimeout));

        if (isset($options['middleware'])) {
            if (!is_iterable($options['middleware'])) {
                throw new ConfigurationException('middleware 选项必须是可迭代的中间件集合');
            }

            foreach ($options['middleware'] as $middleware) {
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new ConfigurationException(sprintf(
                        'middleware 集合中存在非 %s 实例',
                        MiddlewareInterface::class
                    ));
                }
                $stack->add($middleware);
            }
        }

        return $stack;
    }

    /**
     * 根据配置创建认证中间件
     *
     * @param mixed $auth 认证配置
     *
     * @throws ConfigurationException 当认证配置非法时抛出
     */
    private static function createAuthMiddleware(mixed $auth): AuthMiddleware
    {
        if (!is_array($auth)) {
            throw new ConfigurationException('auth 选项必须是数组');
        }

        $type = (string) ($auth['type'] ?? AuthMiddleware::TYPE_BEARER);

        return match ($type) {
            AuthMiddleware::TYPE_BEARER => AuthMiddleware::bearer(
                self::requireCredential($auth)
            ),
            AuthMiddleware::TYPE_API_KEY => AuthMiddleware::apiKey(
                self::requireCredential($auth),
                (string) ($auth['header'] ?? 'X-API-Key')
            ),
            AuthMiddleware::TYPE_BASIC => AuthMiddleware::basic(
                (string) ($auth['username'] ?? ''),
                (string) ($auth['password'] ?? '')
            ),
            default => throw new ConfigurationException(sprintf(
                '未知的认证类型 "%s"，可用值：%s',
                $type,
                implode(', ', [
                    AuthMiddleware::TYPE_BEARER,
                    AuthMiddleware::TYPE_API_KEY,
                    AuthMiddleware::TYPE_BASIC,
                ])
            )),
        };
    }

    /**
     * 取出认证凭据
     *
     * @param array<string, mixed> $auth 认证配置
     * @return string|callable 凭据或凭据提供器
     *
     * @throws ConfigurationException 当缺少凭据时抛出
     */
    private static function requireCredential(array $auth): string|callable
    {
        $credential = $auth['credential'] ?? $auth['token'] ?? null;

        if (is_string($credential) || is_callable($credential)) {
            return $credential;
        }

        throw new ConfigurationException('auth 配置缺少 credential（字符串或 callable）');
    }

    /**
     * 校验顶层配置键
     *
     * @param array<string, mixed> $options 配置选项
     *
     * @throws ConfigurationException 当存在未知键时抛出
     */
    public static function assertOptions(array $options): void
    {
        $unknown = array_diff(array_keys($options), self::SUPPORTED_OPTIONS);

        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                '未知的客户端配置项：%s，可用配置项：%s',
                implode(', ', array_map(strval(...), $unknown)),
                implode(', ', self::SUPPORTED_OPTIONS)
            ));
        }
    }

    /**
     * 创建简单的 HTTP 客户端（无中间件）
     *
     * @param array<string, mixed> $options 配置选项（仅传输层相关生效）
     */
    public static function createSimple(array $options = []): HttpClient
    {
        self::assertOptions($options);

        $transport = self::createTransportOptions($options);
        $driver = self::createDriver(
            is_string($options['driver'] ?? null) ? $options['driver'] : self::DRIVER_AUTO,
            $transport
        );

        return new HttpClient(
            $driver,
            null,
            isset($options['base_uri']) ? (string) $options['base_uri'] : null
        );
    }

    /**
     * 创建带自定义中间件栈的 HTTP 客户端
     *
     * @param MiddlewareStack $middlewareStack 中间件栈
     * @param string $driver 驱动类型
     * @param array<string, mixed> $options 其他配置选项
     */
    public static function createWithMiddleware(
        MiddlewareStack $middlewareStack,
        string $driver = self::DRIVER_AUTO,
        array $options = []
    ): HttpClient {
        self::assertOptions($options);

        return new HttpClient(
            self::createDriver($driver, self::createTransportOptions($options)),
            $middlewareStack,
            isset($options['base_uri']) ? (string) $options['base_uri'] : null
        );
    }

    /**
     * 创建 Fiber 驱动的 HTTP 客户端
     *
     * @param array<string, mixed> $options 配置选项
     */
    public static function createFiber(array $options = []): HttpClient
    {
        $options['driver'] = self::DRIVER_FIBER;

        return self::create($options);
    }

    /**
     * 创建 Swoole 驱动的 HTTP 客户端
     *
     * @param array<string, mixed> $options 配置选项
     */
    public static function createSwoole(array $options = []): HttpClient
    {
        $options['driver'] = self::DRIVER_SWOOLE;

        return self::create($options);
    }

    /**
     * 创建 Swow 驱动的 HTTP 客户端
     *
     * @param array<string, mixed> $options 配置选项
     */
    public static function createSwow(array $options = []): HttpClient
    {
        $options['driver'] = self::DRIVER_SWOW;

        return self::create($options);
    }

    /**
     * 创建 Amp 驱动的 HTTP 客户端
     *
     * @param array<string, mixed> $options 配置选项
     */
    public static function createAmp(array $options = []): HttpClient
    {
        $options['driver'] = self::DRIVER_AMP;

        return self::create($options);
    }

    /**
     * 列出当前环境可用的驱动
     *
     * @return array<string, bool> 驱动名 => 是否可用
     */
    public static function availableDrivers(): array
    {
        return [
            self::DRIVER_CURL => CurlDriver::isSupported(),
            self::DRIVER_FIBER => FiberDriver::isSupported(),
            self::DRIVER_SWOOLE => SwooleDriver::isSupported(),
            self::DRIVER_SWOW => SwowDriver::isSupported(),
            self::DRIVER_AMP => AmpDriver::isSupported(),
        ];
    }
}
