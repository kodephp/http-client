<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 超时中间件
 *
 * 为请求设置超时时间
 * 通过上下文传递超时配置
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class TimeoutMiddleware implements MiddlewareInterface
{
    /**
     * 默认超时时间（秒）
     */
    private readonly float $defaultTimeout;

    /**
     * 构造函数
     *
     * @param float $defaultTimeout 默认超时时间（秒）
     */
    public function __construct(float $defaultTimeout = 30.0)
    {
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $timeout = Context::getTimeout() ?? $this->defaultTimeout;
        Context::setTimeout($timeout);

        return $next($request);
    }
}
