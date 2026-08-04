<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests\Support;

use Kode\HttpClient\Middleware\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 测试用中间件
 *
 * 把任意闭包包装成中间件，便于在测试中观察执行顺序。
 *
 * @package Kode\HttpClient\Tests\Support
 */
final class CallbackMiddleware implements MiddlewareInterface
{
    /**
     * @var \Closure(RequestInterface, callable): ResponseInterface
     */
    private readonly \Closure $handler;

    /**
     * @param callable(RequestInterface, callable): ResponseInterface $handler 处理逻辑
     */
    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        return ($this->handler)($request, $next);
    }
}
