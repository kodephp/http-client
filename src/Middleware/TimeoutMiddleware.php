<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 超时中间件
 *
 * 为请求设置超时时间
 */
class TimeoutMiddleware implements MiddlewareInterface
{
    /**
     * @var float 默认超时时间（秒）
     */
    private float $defaultTimeout;

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
     * @param RequestInterface $request 请求对象
     * @param Context $context 请求上下文
     * @param callable $next 下一个中间件
     * @return ResponseInterface 响应对象
     */
    public function process(RequestInterface $request, Context $context, callable $next): ResponseInterface
    {
        // 如果上下文中没有设置超时时间，则使用默认超时时间
        $timeout = $context->getTimeout() ?? $this->defaultTimeout;
        
        // 创建新的上下文并设置超时时间
        $newContext = $context->withTimeout($timeout);
        
        // 执行下一个中间件
        return $next($request, $newContext);
    }
}