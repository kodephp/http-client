<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Swoole HTTP 驱动
 *
 * 基于 Swoole 协程实现的异步 HTTP 驱动
 */
class SwooleDriver implements DriverInterface
{
    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param Context $context 请求上下文
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当发生网络错误时抛出
     * @throws RequestException 当请求格式错误时抛出
     */
    public function sendRequest(RequestInterface $request, Context $context): ResponseInterface
    {
        // 检查 Swoole 扩展是否可用
        if (!extension_loaded('swoole')) {
            throw new NetworkException('Swoole extension is not loaded', $request);
        }

        // 检查是否在协程环境中
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new NetworkException('Swoole driver must be used in coroutine context', $request);
        }

        // 这里应该实现基于 Swoole 协程客户端的具体逻辑
        // 由于这是一个示例实现，我们抛出一个异常说明需要实际实现
        
        throw new \RuntimeException('SwooleDriver 需要基于 Swoole 协程客户端实现具体的发送逻辑');
    }
}