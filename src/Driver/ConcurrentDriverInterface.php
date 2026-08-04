<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 并发 HTTP 驱动接口
 *
 * 由具备真正并行能力的驱动实现（curl_multi、Swoole 协程、Fiber 等）。
 * 语义为「全部落定」：不会因为其中一个请求失败而中断整批请求，
 * 失败项以异常对象的形式出现在结果数组中，键与入参保持一致。
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
interface ConcurrentDriverInterface extends DriverInterface
{
    /**
     * 并发发送多个 HTTP 请求
     *
     * @param array<array-key, RequestInterface> $requests 请求集合
     * @return array<array-key, ResponseInterface|\Throwable> 结果集合，键与入参一致
     */
    public function sendConcurrent(array $requests): array;
}
