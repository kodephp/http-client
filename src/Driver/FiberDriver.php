<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Fiber;
use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Driver\Internal\CurlHandleFactory;
use Kode\HttpClient\Driver\Internal\HeaderCollector;
use Kode\HttpClient\Exception\NetworkException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Fiber HTTP 驱动
 *
 * 基于 curl_multi + PHP Fiber 的协作式驱动。
 *
 * v2.4 重写要点：
 *  - 修复原实现用 `$fiber->start()` 取返回值导致永远拿不到响应的严重缺陷
 *    （start() 返回的是首次 suspend 的值，正确做法是 getReturn()）
 *  - 不再自行包一层 Fiber，而是「就地协作」：处于 Fiber 中时主动 suspend 让出调度权，
 *    不在 Fiber 中时退化为阻塞 select，两种场景都能正确工作
 *  - 复用同一个 curl_multi 句柄，多个 Fiber 的请求可真正并行传输
 *  - 提供 sendConcurrent() 批量并发能力
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class FiberDriver implements ConcurrentDriverInterface
{
    /**
     * 驱动标识，用于 User-Agent 后缀
     */
    private const string DRIVER_TAG = 'fiber';

    /**
     * 单次 select 等待时长（秒）
     */
    private const float SELECT_TIMEOUT = 0.05;

    /**
     * 共享的 curl_multi 句柄
     */
    private ?\CurlMultiHandle $multiHandle = null;

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
        return class_exists(\Fiber::class) && extension_loaded('curl');
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
        $result = $this->sendConcurrent([$request])[0] ?? null;

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if ($result instanceof \Throwable) {
            throw $result;
        }

        throw new NetworkException('Fiber 驱动未返回任何结果', $request);
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

        $this->assertEnvironment($requests);

        $options = $this->resolveOptions();
        $multi = $this->multiHandle ??= curl_multi_init();

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

            $this->awaitCompletion($multi, $registry);

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

                $results[$entry['key']] = CurlHandleFactory::buildResponse(
                    $handle,
                    $entry['collector'],
                    (string) curl_multi_getcontent($handle)
                );
            }
        } finally {
            foreach ($registry as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
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
     * 等待本批次的全部句柄完成
     *
     * 位于 Fiber 中时通过 Fiber::suspend() 让出执行权，实现协作式并发；
     * 不在 Fiber 中时退化为 curl_multi_select 阻塞等待。
     *
     * @param \CurlMultiHandle $multi curl_multi 句柄
     * @param array<int, array{key: array-key, request: RequestInterface, handle: \CurlHandle, collector: HeaderCollector}> $registry 本批次句柄登记表
     */
    private function awaitCompletion(\CurlMultiHandle $multi, array $registry): void
    {
        $pending = count($registry);

        if ($pending === 0) {
            return;
        }

        $inFiber = Fiber::getCurrent() !== null;
        $running = 0;

        do {
            $status = curl_multi_exec($multi, $running);

            while (($info = curl_multi_info_read($multi)) !== false) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                if (isset($registry[spl_object_id($info['handle'])])) {
                    $pending--;
                }
            }

            if ($pending <= 0 || $status !== CURLM_OK) {
                break;
            }

            if ($inFiber) {
                // 让出调度权，由外部调度器决定何时恢复
                Fiber::suspend();
                continue;
            }

            if (curl_multi_select($multi, self::SELECT_TIMEOUT) === -1) {
                usleep(1000);
            }
        } while (true);
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
     * @param array<array-key, RequestInterface> $requests 请求集合
     *
     * @throws NetworkException 当环境不满足要求时抛出
     */
    private function assertEnvironment(array $requests): void
    {
        $sample = null;
        foreach ($requests as $request) {
            if ($request instanceof RequestInterface) {
                $sample = $request;
                break;
            }
        }

        if ($sample === null) {
            return;
        }

        if (!extension_loaded('curl')) {
            throw new NetworkException('cURL 扩展未加载', $sample);
        }

        if (!class_exists(Fiber::class)) {
            throw new NetworkException('当前 PHP 不支持 Fiber，需要 PHP 8.1+', $sample);
        }
    }

    /**
     * 析构函数 - 释放共享的 multi 句柄
     */
    public function __destruct()
    {
        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
    }
}
