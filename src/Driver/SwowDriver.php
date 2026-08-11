<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Swow HTTP 驱动
 *
 * 基于 Swow 协程扩展（Swow\Psr7\Client\Client）实现。
 * v2.4 新增 —— 此前 README 宣称支持 Swow 但代码中并无对应驱动。
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class SwowDriver implements ConcurrentDriverInterface
{
    /**
     * 驱动标识，用于 User-Agent 后缀
     */
    private const string DRIVER_TAG = 'swow';

    /**
     * Swow PSR-7 客户端类名
     */
    private const string CLIENT_CLASS = 'Swow\\Psr7\\Client\\Client';

    /**
     * Swow 协程类名
     */
    private const string COROUTINE_CLASS = 'Swow\\Coroutine';

    /**
     * 默认传输配置
     */
    private readonly ?TransportOptions $defaults;

    /**
     * 构造函数
     *
     * @param TransportOptions|null $defaults 驱动级默认传输配置
     */
    public function __construct(?TransportOptions $defaults = null)
    {
        $this->defaults = $defaults;
    }

    /**
     * 当前环境是否可用
     */
    public static function isSupported(): bool
    {
        return extension_loaded('swow') && class_exists(self::CLIENT_CLASS);
    }

    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当发生网络错误时抛出
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->assertEnvironment($request);

        return $this->execute($request, $this->resolveOptions());
    }

    /**
     * 并发发送多个 HTTP 请求
     *
     * @param array<array-key, RequestInterface> $requests 请求集合
     * @return array<array-key, ResponseInterface|\Throwable> 结果集合，键与入参一致
     */
    #[\Override]
    public function sendConcurrent(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $options = $this->resolveOptions();
        $results = [];
        $coroutines = [];

        /** @var class-string $coroutineClass */
        $coroutineClass = self::COROUTINE_CLASS;

        foreach ($requests as $key => $request) {
            if (!$request instanceof RequestInterface) {
                $results[$key] = new \InvalidArgumentException('并发请求集合中存在非 PSR-7 请求对象');
                continue;
            }

            $coroutines[] = $coroutineClass::run(function () use ($key, $request, $options, &$results): void {
                try {
                    $results[$key] = $this->execute($request, $options);
                } catch (\Throwable $e) {
                    $results[$key] = $e;
                }
            });
        }

        foreach ($coroutines as $coroutine) {
            if (is_object($coroutine) && method_exists($coroutine, 'join')) {
                $coroutine->join();
            }
        }

        $ordered = [];
        foreach ($requests as $key => $_) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * 执行单个请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param TransportOptions $options 传输配置
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当请求失败时抛出
     */
    private function execute(RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $ssl = $uri->getScheme() === 'https';

        /** @var class-string $clientClass */
        $clientClass = self::CLIENT_CLASS;

        try {
            $client = new $clientClass();

            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout((int) round($options->timeout * 1000));
            }

            $client->connect($host, $port, (int) round($options->connectTimeout * 1000));

            if ($ssl && method_exists($client, 'enableCrypto')) {
                $client->enableCrypto();
            }

            $outgoing = $this->decorateRequest($request, $options, $host, $port);

            /** @var ResponseInterface $response */
            $response = $client->sendRequest($outgoing);

            return MessageFactory::createResponse(
                $response->getStatusCode(),
                $response->getHeaders(),
                (string) $response->getBody(),
                $response->getProtocolVersion(),
                $response->getReasonPhrase() !== '' ? $response->getReasonPhrase() : null
            );
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Swow 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        } finally {
            if (isset($client) && is_object($client) && method_exists($client, 'close')) {
                $client->close();
            }
        }
    }

    /**
     * 补齐默认请求头
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param TransportOptions $options 传输配置
     * @param string $host 目标主机
     * @param int $port 目标端口
     * @return RequestInterface 补齐后的请求
     */
    private function decorateRequest(
        RequestInterface $request,
        TransportOptions $options,
        string $host,
        int $port
    ): RequestInterface {
        $defaults = array_merge(
            [
                'Accept' => '*/*',
                'User-Agent' => $options->userAgent . ' (' . self::DRIVER_TAG . ')',
                'Host' => in_array($port, [80, 443], true) ? $host : $host . ':' . $port,
            ],
            $options->defaultHeaders
        );

        foreach ($defaults as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        return $request;
    }

    /**
     * 解析本次请求实际生效的传输配置
     */
    private function resolveOptions(): TransportOptions
    {
        $contextOptions = Context::getTransportOptions();

        if ($this->defaults === null) {
            return $contextOptions;
        }

        $timeout = Context::getTimeout();

        return $timeout !== null
            ? $this->defaults->with(['timeout' => $timeout])
            : $this->defaults;
    }

    /**
     * 校验运行环境
     *
     * @param RequestInterface $request PSR-7 请求对象
     *
     * @throws NetworkException 当环境不满足要求时抛出
     */
    private function assertEnvironment(RequestInterface $request): void
    {
        if (!self::isSupported()) {
            throw new NetworkException('Swow 扩展未加载或 Swow\\Psr7 客户端不可用', $request);
        }
    }
}
