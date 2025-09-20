<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\Context\Context;
use Kode\HttpClient\Exception\HttpException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件栈
 */
class MiddlewareStack
{
    /**
     * @var MiddlewareInterface[] 中间件数组
     */
    private array $middlewares = [];

    /**
     * 添加中间件
     *
     * @param MiddlewareInterface $middleware 中间件
     * @return void
     */
    public function add(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request 请求对象
     * @param Context $context 请求上下文
     * @param callable $handler 最终处理器
     * @return ResponseInterface 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function handle(RequestInterface $request, Context $context, callable $handler): ResponseInterface
    {
        $next = $this->createNextHandler($handler, 0);
        return $next($request, $context);
    }

    /**
     * 创建下一个处理器
     *
     * @param callable $handler 最终处理器
     * @param int $index 当前中间件索引
     * @return callable 下一个处理器
     */
    private function createNextHandler(callable $handler, int $index): callable
    {
        // 如果已经处理完所有中间件，则调用最终处理器
        if ($index >= count($this->middlewares)) {
            return $handler;
        }

        // 获取当前中间件
        $middleware = $this->middlewares[$index];

        // 创建下一个处理器
        $next = $this->createNextHandler($handler, $index + 1);

        // 返回当前中间件的处理函数
        return function (RequestInterface $request, Context $context) use ($middleware, $next): ResponseInterface {
            return $middleware->process($request, $context, $next);
        };
    }
}