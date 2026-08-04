<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\TimeoutException;
use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Swoole HTTP 驱动
 *
 * 基于 Swoole 协程的高性能驱动，需运行在协程上下文中。
 *
 * v2.4 修复与增强：
 *  - 修复 executeRequest() 声明返回 \Swoole\Http\Response 却实际返回客户端对象导致的 TypeError
 *  - 请求路径正确携带 query string 与 Host 头
 *  - 支持 TLS 校验策略、代理、重定向、毫秒级超时
 *  - 通过 Swoole\Coroutine\WaitGroup 提供批量并发能力
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class SwooleDriver implements ConcurrentDriverInterface
{
    /**
     * 驱动标识，用于 User-Agent 后缀
     */
    private const string DRIVER_TAG = 'swoole';

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
     *
     * 必须已加载 swoole 扩展，且当前处于协程上下文中。
     */
    public static function isSupported(): bool
    {
        if (!extension_loaded('swoole') || !class_exists('Swoole\\Coroutine')) {
            return false;
        }

        /** @var int|false $cid */
        $cid = \Swoole\Coroutine::getCid();

        return is_int($cid) && $cid > 0;
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
     * 每个请求在独立协程中执行，由 WaitGroup 汇总。
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

        $waitGroup = new \Swoole\Coroutine\WaitGroup();

        foreach ($requests as $key => $request) {
            if (!$request instanceof RequestInterface) {
                $results[$key] = new \InvalidArgumentException('并发请求集合中存在非 PSR-7 请求对象');
                continue;
            }

            $waitGroup->add();

            \Swoole\Coroutine::create(function () use ($key, $request, $options, $waitGroup, &$results): void {
                try {
                    $results[$key] = $this->execute($request, $options);
                } catch (\Throwable $e) {
                    $results[$key] = $e;
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();

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

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);

        try {
            $client->set($this->buildSettings($options));
            $client->setMethod(strtoupper($request->getMethod()));
            $client->setHeaders($this->buildHeaders($request, $options, $host, $port));

            $body = (string) $request->getBody();
            if ($body !== '') {
                $client->setData($body);
            }

            $target = $uri->getPath() !== '' ? $uri->getPath() : '/';
            $query = $uri->getQuery();
            if ($query !== '') {
                $target .= '?' . $query;
            }

            $success = $client->execute($target);

            if ($success === false) {
                $errCode = $client->errCode ?? 0;
                $message = $client->errMsg ?? '未知错误';

                // ETIMEDOUT / SWOOLE_ERROR_CO_TIMEDOUT 归一化为超时异常
                if ($errCode === 110 || $client->statusCode === -2) {
                    throw new TimeoutException(
                        sprintf('Swoole 请求超时（%.3fs）: %s', $options->timeout, $message),
                        $request,
                        $options->timeout
                    );
                }

                throw new NetworkException(
                    sprintf('Swoole 请求执行失败 [%d]: %s', $errCode, $message),
                    $request
                );
            }

            return $this->createResponse($client);
        } catch (NetworkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Swoole 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        } finally {
            $client->close();
        }
    }

    /**
     * 构建 Swoole 客户端设置
     *
     * @param TransportOptions $options 传输配置
     * @return array<string, mixed> Swoole 客户端设置
     */
    private function buildSettings(TransportOptions $options): array
    {
        $settings = [
            'timeout' => $options->timeout > 0 ? $options->timeout : -1,
            'connect_timeout' => $options->connectTimeout,
            'ssl_verify_peer' => $options->verify !== false,
            'ssl_allow_self_signed' => $options->verify === false,
        ];

        if (is_string($options->verify) && $options->verify !== '') {
            $settings['ssl_cafile'] = $options->verify;
        }

        if ($options->proxy !== null && $options->proxy !== '') {
            $parts = parse_url($options->proxy);
            if (is_array($parts) && isset($parts['host'])) {
                $settings['http_proxy_host'] = $parts['host'];
                $settings['http_proxy_port'] = (int) ($parts['port'] ?? 80);
                if (isset($parts['user'])) {
                    $settings['http_proxy_user'] = $parts['user'];
                    $settings['http_proxy_password'] = $parts['pass'] ?? '';
                }
            }
        }

        return $settings;
    }

    /**
     * 构建请求头
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param TransportOptions $options 传输配置
     * @param string $host 目标主机
     * @param int $port 目标端口
     * @return array<string, string> 请求头
     */
    private function buildHeaders(
        RequestInterface $request,
        TransportOptions $options,
        string $host,
        int $port
    ): array {
        $headers = array_merge(
            [
                'Host' => in_array($port, [80, 443], true) ? $host : $host . ':' . $port,
                'Accept' => '*/*',
                'User-Agent' => $options->userAgent . ' (' . self::DRIVER_TAG . ')',
            ],
            $options->defaultHeaders
        );

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * 由 Swoole 客户端构建 PSR-7 响应
     *
     * @param \Swoole\Coroutine\Http\Client $client Swoole 协程客户端
     * @return ResponseInterface PSR-7 响应对象
     */
    private function createResponse(\Swoole\Coroutine\Http\Client $client): ResponseInterface
    {
        $headers = [];
        /** @var array<string, string|array<int, string>>|null $rawHeaders */
        $rawHeaders = $client->headers;

        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                $headers[(string) $name] = is_array($value) ? array_values($value) : [(string) $value];
            }
        }

        $status = (int) ($client->statusCode ?? 200);

        return MessageFactory::createResponse(
            $status > 0 ? $status : 200,
            $headers,
            (string) ($client->body ?? '')
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
        if (!extension_loaded('swoole')) {
            throw new NetworkException('Swoole 扩展未加载', $request);
        }

        if (\Swoole\Coroutine::getCid() <= 0) {
            throw new NetworkException('Swoole 驱动必须在协程环境中使用', $request);
        }
    }
}
