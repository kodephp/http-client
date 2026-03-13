<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 中间件接口
 *
 * 定义 HTTP 中间件的标准接口
 * 遵循 PSR-15 风格的中间件设计
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
interface MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function process(RequestInterface $request, callable $next): ResponseInterface;
}
