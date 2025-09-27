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
 */
class HttpClient implements HttpClientInterface
{
    /**
     * @var DriverInterface HTTP 驱动
     */
    private DriverInterface $driver;

    /**
     * @var MiddlewareStack|null 中间件栈
     */
    private ?MiddlewareStack $middlewareStack;

    /**
     * 构造函数
     *
     * @param DriverInterface $driver HTTP 驱动
     * @param MiddlewareStack|null $middlewareStack 中间件栈
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
     * @param Context|null $context 请求上下文
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function sendRequest(RequestInterface $request, ?Context $context = null): ResponseInterface
    {
        // 如果有中间件栈，则通过中间件栈处理请求
        if ($this->middlewareStack !== null) {
            $context = $context ?? new Context();
            
            return $this->middlewareStack->handle(
                $request,
                $context,
                fn(RequestInterface $req, Context $ctx) => $this->driver->sendRequest($req, $ctx)
            );
        }
        
        // 否则直接通过驱动发送请求
        return $this->driver->sendRequest($request, $context ?? new Context());
    }

    /**
     * 获取驱动
     * 
     * @return DriverInterface HTTP 驱动
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
}