<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件栈
 *
 * 管理并执行中间件链。先加入的中间件位于更外层，
 * 即执行顺序为：add 顺序进入 → 驱动 → add 逆序返回。
 *
 * v2.4 增强：链式调用、批量添加、前置插入、不可变派生、迭代与清空。
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MiddlewareStack implements \Countable, \IteratorAggregate
{
    /**
     * 中间件数组
     *
     * @var list<MiddlewareInterface>
     */
    private array $middlewares = [];

    /**
     * 构造函数
     *
     * @param iterable<MiddlewareInterface> $middlewares 初始中间件
     */
    public function __construct(iterable $middlewares = [])
    {
        foreach ($middlewares as $middleware) {
            $this->add($middleware);
        }
    }

    /**
     * 追加中间件（位于当前链的最内层）
     *
     * @param MiddlewareInterface $middleware 中间件实例
     * @return $this 支持链式调用
     */
    public function add(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * 批量追加中间件
     *
     * @param iterable<MiddlewareInterface> $middlewares 中间件集合
     * @return $this 支持链式调用
     */
    public function addMany(iterable $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->add($middleware);
        }

        return $this;
    }

    /**
     * 前置中间件（位于当前链的最外层）
     *
     * @param MiddlewareInterface $middleware 中间件实例
     * @return $this 支持链式调用
     */
    public function prepend(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middlewares, $middleware);

        return $this;
    }

    /**
     * 派生一个追加了中间件的新栈（原栈不变）
     *
     * @param MiddlewareInterface ...$middleware 中间件实例
     * @return self 新的中间件栈
     */
    public function with(MiddlewareInterface ...$middleware): self
    {
        return (new self($this->middlewares))->addMany($middleware);
    }

    /**
     * 清空中间件
     *
     * @return $this 支持链式调用
     */
    public function clear(): self
    {
        $this->middlewares = [];

        return $this;
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $handler 最终处理器（通常是驱动）
     * @return ResponseInterface PSR-7 响应对象
     */
    public function handle(RequestInterface $request, callable $handler): ResponseInterface
    {
        $next = $handler;

        // 逆序包装，避免递归构建带来的额外栈深度
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $current = $next;
            $next = static fn(RequestInterface $req): ResponseInterface => $middleware->process($req, $current);
        }

        return $next($request);
    }

    /**
     * 获取全部中间件
     *
     * @return list<MiddlewareInterface> 中间件列表
     */
    public function all(): array
    {
        return $this->middlewares;
    }

    /**
     * 获取中间件数量
     */
    #[\Override]
    public function count(): int
    {
        return count($this->middlewares);
    }

    /**
     * 检查中间件栈是否为空
     */
    public function isEmpty(): bool
    {
        return $this->middlewares === [];
    }

    /**
     * 迭代中间件
     *
     * @return \Traversable<int, MiddlewareInterface>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->middlewares);
    }
}
