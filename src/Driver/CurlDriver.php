<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Driver\Internal\CurlHandleFactory;
use Kode\HttpClient\Driver\Internal\HeaderCollector;
use Kode\HttpClient\Exception\NetworkException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Curl HTTP 驱动
 *
 * 基于 PHP curl 扩展的同步驱动，是 FPM / CLI 环境下的默认选择。
 *
 * v2.4 改进：
 *  - 使用 CURLOPT_HEADERFUNCTION 精确捕获「最后一跳」响应头，修复重定向场景头部串味问题
 *  - HEAD 请求改用 CURLOPT_NOBODY，避免挂起等待不存在的响应体
 *  - 超时精度提升到毫秒（CURLOPT_TIMEOUT_MS / CURLOPT_CONNECTTIMEOUT_MS）
 *  - 保留响应状态短语与协议版本
 *  - 支持 gzip/deflate/br 自动解压、代理、TLS 校验策略、自定义 curl 选项
 *  - 通过 curl_multi 提供真正的并发请求能力
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CurlDriver implements ConcurrentDriverInterface
{
    /**
     * 驱动标识，用于 User-Agent 后缀
     */
    private const string DRIVER_TAG = 'curl';

    /**
     * 默认传输配置
     */
    private readonly ?TransportOptions $defaults;

    /**
     * 构造函数
     *
     * @param TransportOptions|null $defaults 驱动级默认传输配置，null 表示完全依赖上下文
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
        return extension_loaded('curl');
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
        $this->assertCurlAvailable($request);

        $options = $this->resolveOptions();
        $collector = new HeaderCollector();
        $handle = CurlHandleFactory::create($request, $options, $collector, self::DRIVER_TAG);

        try {
            $body = curl_exec($handle);

            if ($body === false) {
                throw CurlHandleFactory::toException(
                    curl_errno($handle),
                    curl_error($handle),
                    $request,
                    $options
                );
            }

            return CurlHandleFactory::buildResponse($handle, $collector, (string) $body);
        } finally {
            curl_close($handle);
        }
    }

    /**
     * 并发发送多个 HTTP 请求
     *
     * 使用 curl_multi 在单线程内并行执行，整体耗时接近最慢的那个请求。
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

        $first = reset($requests);
        if ($first instanceof RequestInterface) {
            $this->assertCurlAvailable($first);
        }

        $options = $this->resolveOptions();
        $multi = curl_multi_init();

        /** @var array<int, array{key: array-key, request: RequestInterface, handle: \CurlHandle, collector: HeaderCollector}> $registry */
        $registry = [];
        $results = [];

        try {
            foreach ($requests as $key => $request) {
                if (!$request instanceof RequestInterface) {
                    $results[$key] = new \InvalidArgumentException('并发请求集合中存在非 PSR-7 请求对象');
                    continue;
                }

                $collector = new HeaderCollector();
                $handle = CurlHandleFactory::create($request, $options, $collector, self::DRIVER_TAG);

                curl_multi_add_handle($multi, $handle);
                $registry[spl_object_id($handle)] = [
                    'key' => $key,
                    'request' => $request,
                    'handle' => $handle,
                    'collector' => $collector,
                ];
            }

            $this->runMulti($multi);

            foreach ($registry as $entry) {
                $handle = $entry['handle'];
                $errno = curl_errno($handle);

                if ($errno !== 0) {
                    $results[$entry['key']] = CurlHandleFactory::toException(
                        $errno,
                        curl_error($handle),
                        $entry['request'],
                        $options
                    );
                    continue;
                }

                $body = curl_multi_getcontent($handle);
                $results[$entry['key']] = CurlHandleFactory::buildResponse(
                    $handle,
                    $entry['collector'],
                    (string) $body
                );
            }
        } finally {
            foreach ($registry as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
            curl_multi_close($multi);
        }

        // 保持与入参一致的顺序
        $ordered = [];
        foreach ($requests as $key => $_) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * 驱动执行 curl_multi 事件循环直至全部完成
     *
     * @param \CurlMultiHandle $multi curl_multi 句柄
     */
    private function runMulti(\CurlMultiHandle $multi): void
    {
        $running = 0;

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                // 返回 -1 表示无文件描述符可等待，退避 1ms 避免空转打满 CPU
                if (curl_multi_select($multi, 0.5) === -1) {
                    usleep(1000);
                }
            }

            while (curl_multi_info_read($multi) !== false) {
                // 逐条消费完成通知，防止内部队列堆积
            }
        } while ($running > 0 && $status === CURLM_OK);
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
     * 校验 curl 扩展可用性
     *
     * @param RequestInterface $request PSR-7 请求对象
     *
     * @throws NetworkException 当 curl 扩展未加载时抛出
     */
    private function assertCurlAvailable(RequestInterface $request): void
    {
        if (!extension_loaded('curl')) {
            throw new NetworkException('cURL 扩展未加载', $request);
        }
    }
}
