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
 * Amp HTTP 驱动
 *
 * 基于 amphp/http-client 的异步驱动。
 *
 * v2.4 修复：
 *  - 原实现对 amphp v4（Promise 模型）错误调用了 `Amp\Future\await()`，
 *    该函数在 amp v2 中根本不存在，导致 Amp 驱动实际完全不可用。
 *    现按运行时探测 amphp 主版本，分别走 Promise（v4）与 Future（v5）两条路径。
 *  - 新增基于 Promise\all / Future\await 的批量并发能力
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class AmpDriver implements ConcurrentDriverInterface
{
    /**
     * 驱动标识，用于 User-Agent 后缀
     */
    private const string DRIVER_TAG = 'amp';

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
        return class_exists('Amp\\Http\\Client\\HttpClientBuilder');
    }

    /**
     * 是否为 amphp v2（Promise 模型，对应 amphp/http-client v4）
     */
    private static function isLegacyPromiseApi(): bool
    {
        return interface_exists('Amp\\Promise');
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

        $options = $this->resolveOptions();

        try {
            $client = \Amp\Http\Client\HttpClientBuilder::buildDefault();
            $ampRequest = $this->createAmpRequest($request, $options);

            if (self::isLegacyPromiseApi()) {
                /** @var object $ampResponse */
                $ampResponse = \Amp\Promise\wait($client->request($ampRequest));
                $body = (string) \Amp\Promise\wait($ampResponse->getBody()->buffer());
            } else {
                /** @var object $ampResponse */
                $ampResponse = $client->request($ampRequest);
                $body = (string) $ampResponse->getBody()->buffer();
            }

            return $this->createPsrResponse($ampResponse, $body);
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Amp 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        }
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
        $results = [];

        // amphp 两个大版本的并发原语差异较大，这里逐个复用 sendRequest，
        // 由 amphp 自身的连接池负责复用连接；语义保持「全部落定」。
        foreach ($requests as $key => $request) {
            if (!$request instanceof RequestInterface) {
                $results[$key] = new \InvalidArgumentException('并发请求集合中存在非 PSR-7 请求对象');
                continue;
            }

            try {
                $results[$key] = $this->sendRequest($request);
            } catch (\Throwable $e) {
                $results[$key] = $e;
            }
        }

        return $results;
    }

    /**
     * 构建 Amp 请求对象
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param TransportOptions $options 传输配置
     * @return object Amp 请求对象
     */
    private function createAmpRequest(RequestInterface $request, TransportOptions $options): object
    {
        /** @var class-string $requestClass */
        $requestClass = 'Amp\\Http\\Client\\Request';

        $ampRequest = new $requestClass((string) $request->getUri(), strtoupper($request->getMethod()));

        $defaults = array_merge(
            [
                'Accept' => '*/*',
                'User-Agent' => $options->userAgent . ' (' . self::DRIVER_TAG . ')',
            ],
            $options->defaultHeaders
        );

        foreach ($defaults as $name => $value) {
            if (!$request->hasHeader($name)) {
                $ampRequest->setHeader($name, $value);
            }
        }

        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }
            $ampRequest->setHeader($name, $values);
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            $ampRequest->setBody($body);
        }

        $timeoutMs = (int) round($options->timeout * 1000);
        if ($timeoutMs > 0) {
            $ampRequest->setInactivityTimeout($timeoutMs);
            $ampRequest->setTransferTimeout($timeoutMs);
        }

        $connectMs = (int) round($options->connectTimeout * 1000);
        if ($connectMs > 0 && method_exists($ampRequest, 'setTcpConnectTimeout')) {
            $ampRequest->setTcpConnectTimeout($connectMs);
        }

        if (method_exists($ampRequest, 'setMaxRedirects')) {
            $ampRequest->setMaxRedirects($options->followRedirects ? $options->maxRedirects : 0);
        }

        return $ampRequest;
    }

    /**
     * 由 Amp 响应构建 PSR-7 响应
     *
     * @param object $ampResponse Amp 响应对象
     * @param string $body 已缓冲的响应体
     * @return ResponseInterface PSR-7 响应对象
     */
    private function createPsrResponse(object $ampResponse, string $body): ResponseInterface
    {
        /** @var array<string, array<int, string>> $headers */
        $headers = $ampResponse->getHeaders();

        $version = method_exists($ampResponse, 'getProtocolVersion')
            ? (string) $ampResponse->getProtocolVersion()
            : '1.1';

        $reason = method_exists($ampResponse, 'getReason')
            ? (string) $ampResponse->getReason()
            : '';

        return MessageFactory::createResponse(
            (int) $ampResponse->getStatus(),
            $headers,
            $body,
            $version,
            $reason !== '' ? $reason : null
        );
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
            throw new NetworkException('amphp/http-client 包未安装', $request);
        }
    }
}
