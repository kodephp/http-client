<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件栈
 *
 * 管理和执行中间件链
 * 支持中间件的添加和链式调用
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class MiddlewareStack
{
    /**
     * 中间件数组
     *
     * @var array<int, MiddlewareInterface>
     */
    private array $middlewares = [];

    /**
     * 添加中间件
     *
     * @param MiddlewareInterface $middleware 中间件实例
     */
    public function add(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $handler 最终处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function handle(RequestInterface $request, callable $handler): ResponseInterface
    {
        $next = $this->createNextHandler($handler, 0);
        return $next($request);
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
        if ($index >= count($this->middlewares)) {
            return $handler;
        }

        $middleware = $this->middlewares[$index];
        $next = $this->createNextHandler($handler, $index + 1);

        return fn(RequestInterface $request): ResponseInterface => $middleware->process($request, $next);
    }

    /**
     * 获取中间件数量
     *
     * @return int 中间件数量
     */
    public function count(): int
    {
        return count($this->middlewares);
    }

    /**
     * 检查中间件栈是否为空
     *
     * @return bool 是否为空
     */
    public function isEmpty(): bool
    {
        return empty($this->middlewares);
    }
}
