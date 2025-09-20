<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件接口
 */
interface MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param RequestInterface $request 请求对象
     * @param Context $context 请求上下文
     * @param callable $next 下一个中间件
     * @return ResponseInterface 响应对象
     */
    public function process(RequestInterface $request, Context $context, callable $next): ResponseInterface;
}