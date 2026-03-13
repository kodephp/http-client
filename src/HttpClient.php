<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\Exception\HttpException;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 客户端实现
 *
 * 支持多种运行时环境（FPM、CLI、Swoole、Swow、Fiber）
 * 提供中间件机制和上下文管理功能
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
class HttpClient implements HttpClientInterface
{
    /**
     * HTTP 驱动实例
     */
    private DriverInterface $driver;

    /**
     * 中间件栈（可选）
     */
    private ?MiddlewareStack $middlewareStack;

    /**
     * 构造函数
     *
     * @param DriverInterface $driver HTTP 驱动实例
     * @param MiddlewareStack|null $middlewareStack 中间件栈（可选）
     */
    public function __construct(DriverInterface $driver, ?MiddlewareStack $middlewareStack = null)
    {
        $this->driver = $driver;
        $this->middlewareStack = $middlewareStack;
    }

    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->middlewareStack !== null) {
            return $this->middlewareStack->handle(
                $request,
                fn(RequestInterface $req): ResponseInterface => $this->driver->sendRequest($req)
            );
        }

        return $this->driver->sendRequest($request);
    }

    /**
     * 获取驱动实例
     *
     * @return DriverInterface HTTP 驱动实例
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * 获取中间件栈
     *
     * @return MiddlewareStack|null 中间件栈
     */
    public function getMiddlewareStack(): ?MiddlewareStack
    {
        return $this->middlewareStack;
    }

    /**
     * 创建新的客户端实例并添加中间件
     *
     * @param MiddlewareStack $middlewareStack 中间件栈
     * @return self 新的客户端实例
     */
    public function withMiddlewareStack(MiddlewareStack $middlewareStack): self
    {
        return new self($this->driver, $middlewareStack);
    }
}
