<?php

declare(strict_types=1);

namespace Kode\HttpClient\Message;

use Kode\HttpClient\Exception\ConfigurationException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 消息工厂发现器
 *
 * 本类解除了对具体 PSR-7 实现（如 guzzlehttp/psr7）的硬编码依赖，
 * 自动探测项目中已安装的 PSR-17 工厂实现并复用之；
 * 也允许调用方通过 {@see self::register()} 显式注入自定义工厂。
 *
 * 支持自动发现的实现：
 *  - guzzlehttp/psr7        (GuzzleHttp\Psr7\HttpFactory)
 *  - nyholm/psr7            (Nyholm\Psr7\Factory\Psr17Factory)
 *  - httpsoft/http-message  (HttpSoft\Message\*Factory)
 *  - laminas/laminas-diactoros
 *  - slim/psr7
 *  - php-http/discovery     (Http\Discovery\Psr17FactoryDiscovery)
 *
 * @package Kode\HttpClient\Message
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class MessageFactory
{
    /**
     * 同时实现四种 PSR-17 工厂接口的「一体化」候选实现
     *
     * @var list<class-string>
     */
    private const array UNIFIED_CANDIDATES = [
        'GuzzleHttp\\Psr7\\HttpFactory',
        'Nyholm\\Psr7\\Factory\\Psr17Factory',
    ];

    /**
     * 按接口划分的候选实现
     *
     * @var array<string, list<class-string>>
     */
    private const array SPLIT_CANDIDATES = [
        ResponseFactoryInterface::class => [
            'HttpSoft\\Message\\ResponseFactory',
            'Laminas\\Diactoros\\ResponseFactory',
            'Slim\\Psr7\\Factory\\ResponseFactory',
        ],
        RequestFactoryInterface::class => [
            'HttpSoft\\Message\\RequestFactory',
            'Laminas\\Diactoros\\RequestFactory',
            'Slim\\Psr7\\Factory\\RequestFactory',
        ],
        StreamFactoryInterface::class => [
            'HttpSoft\\Message\\StreamFactory',
            'Laminas\\Diactoros\\StreamFactory',
            'Slim\\Psr7\\Factory\\StreamFactory',
        ],
        UriFactoryInterface::class => [
            'HttpSoft\\Message\\UriFactory',
            'Laminas\\Diactoros\\UriFactory',
            'Slim\\Psr7\\Factory\\UriFactory',
        ],
    ];

    private static ?ResponseFactoryInterface $responseFactory = null;

    private static ?RequestFactoryInterface $requestFactory = null;

    private static ?StreamFactoryInterface $streamFactory = null;

    private static ?UriFactoryInterface $uriFactory = null;

    /**
     * 私有构造函数，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 注册自定义 PSR-17 工厂
     *
     * 传入的对象实现了哪些工厂接口就注册哪些，可多次调用逐个补齐。
     *
     * @param object $factory 实现了任意 PSR-17 工厂接口的对象
     *
     * @throws ConfigurationException 当对象未实现任何 PSR-17 工厂接口时抛出
     */
    public static function register(object $factory): void
    {
        $matched = false;

        if ($factory instanceof ResponseFactoryInterface) {
            self::$responseFactory = $factory;
            $matched = true;
        }
        if ($factory instanceof RequestFactoryInterface) {
            self::$requestFactory = $factory;
            $matched = true;
        }
        if ($factory instanceof StreamFactoryInterface) {
            self::$streamFactory = $factory;
            $matched = true;
        }
        if ($factory instanceof UriFactoryInterface) {
            self::$uriFactory = $factory;
            $matched = true;
        }

        if (!$matched) {
            throw new ConfigurationException(sprintf(
                '对象 %s 未实现任何 PSR-17 工厂接口',
                $factory::class
            ));
        }
    }

    /**
     * 重置已注册/已发现的工厂（主要用于测试）
     */
    public static function reset(): void
    {
        self::$responseFactory = null;
        self::$requestFactory = null;
        self::$streamFactory = null;
        self::$uriFactory = null;
    }

    /**
     * 获取响应工厂
     *
     * @throws ConfigurationException 当找不到任何 PSR-17 实现时抛出
     */
    public static function responseFactory(): ResponseFactoryInterface
    {
        /** @var ResponseFactoryInterface */
        return self::$responseFactory ??= self::discover(ResponseFactoryInterface::class);
    }

    /**
     * 获取请求工厂
     *
     * @throws ConfigurationException 当找不到任何 PSR-17 实现时抛出
     */
    public static function requestFactory(): RequestFactoryInterface
    {
        /** @var RequestFactoryInterface */
        return self::$requestFactory ??= self::discover(RequestFactoryInterface::class);
    }

    /**
     * 获取流工厂
     *
     * @throws ConfigurationException 当找不到任何 PSR-17 实现时抛出
     */
    public static function streamFactory(): StreamFactoryInterface
    {
        /** @var StreamFactoryInterface */
        return self::$streamFactory ??= self::discover(StreamFactoryInterface::class);
    }

    /**
     * 获取 URI 工厂
     *
     * @throws ConfigurationException 当找不到任何 PSR-17 实现时抛出
     */
    public static function uriFactory(): UriFactoryInterface
    {
        /** @var UriFactoryInterface */
        return self::$uriFactory ??= self::discover(UriFactoryInterface::class);
    }

    /**
     * 创建 PSR-7 响应
     *
     * @param int $status HTTP 状态码
     * @param array<string, string|array<int, string>> $headers 响应头
     * @param string $body 响应体
     * @param string $protocolVersion HTTP 协议版本
     * @param string|null $reasonPhrase 状态短语，null 表示使用默认值
     */
    public static function createResponse(
        int $status = 200,
        array $headers = [],
        string $body = '',
        string $protocolVersion = '1.1',
        ?string $reasonPhrase = null
    ): ResponseInterface {
        $response = self::responseFactory()->createResponse($status, $reasonPhrase ?? '');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if ($body !== '') {
            $response = $response->withBody(self::createStream($body));
        }

        return $response->withProtocolVersion($protocolVersion);
    }

    /**
     * 创建 PSR-7 请求
     *
     * @param string $method HTTP 方法
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, string|array<int, string>> $headers 请求头
     * @param string $body 请求体
     */
    public static function createRequest(
        string $method,
        string|UriInterface $uri,
        array $headers = [],
        string $body = ''
    ): RequestInterface {
        $request = self::requestFactory()->createRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== '') {
            $request = $request->withBody(self::createStream($body));
        }

        return $request;
    }

    /**
     * 创建 PSR-7 流
     *
     * @param string $content 流内容
     */
    public static function createStream(string $content = ''): StreamInterface
    {
        return self::streamFactory()->createStream($content);
    }

    /**
     * 创建 PSR-7 URI
     *
     * @param string $uri URI 字符串
     */
    public static function createUri(string $uri = ''): UriInterface
    {
        return self::uriFactory()->createUri($uri);
    }

    /**
     * 自动发现指定接口的 PSR-17 工厂实现
     *
     * @param class-string $interface 目标工厂接口
     * @return object 工厂实例
     *
     * @throws ConfigurationException 当找不到任何可用实现时抛出
     */
    private static function discover(string $interface): object
    {
        foreach (self::UNIFIED_CANDIDATES as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, $interface)) {
                return new $candidate();
            }
        }

        foreach (self::SPLIT_CANDIDATES[$interface] ?? [] as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, $interface)) {
                return new $candidate();
            }
        }

        $discovered = self::discoverViaHttplug($interface);
        if ($discovered !== null) {
            return $discovered;
        }

        throw new ConfigurationException(sprintf(
            '未找到 %s 的可用实现，请安装 PSR-7/PSR-17 包（推荐 composer require guzzlehttp/psr7 或 nyholm/psr7），'
                . '或调用 MessageFactory::register() 注入自定义工厂',
            $interface
        ));
    }

    /**
     * 通过 php-http/discovery 发现工厂实现
     *
     * @param class-string $interface 目标工厂接口
     * @return object|null 工厂实例，未找到时返回 null
     */
    private static function discoverViaHttplug(string $interface): ?object
    {
        $discovery = 'Http\\Discovery\\Psr17FactoryDiscovery';

        if (!class_exists($discovery)) {
            return null;
        }

        $method = match ($interface) {
            ResponseFactoryInterface::class => 'findResponseFactory',
            RequestFactoryInterface::class => 'findRequestFactory',
            StreamFactoryInterface::class => 'findStreamFactory',
            UriFactoryInterface::class => 'findUriFactory',
            default => null,
        };

        if ($method === null || !method_exists($discovery, $method)) {
            return null;
        }

        try {
            /** @var object $factory */
            $factory = $discovery::$method();
            return $factory;
        } catch (\Throwable) {
            return null;
        }
    }
}
