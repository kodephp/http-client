<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 重试中间件
 *
 * 支持网络异常重试和指数退避策略
 * 自动重试失败的请求
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class RetryMiddleware implements MiddlewareInterface
{
    /**
     * 最大重试次数
     */
    private readonly int $maxRetries;

    /**
     * 初始退避时间（毫秒）
     */
    private readonly int $initialBackoff;

    /**
     * 退避乘数
     */
    private readonly float $backoffMultiplier;

    /**
     * 构造函数
     *
     * @param int $maxRetries 最大重试次数
     * @param int $initialBackoff 初始退避时间（毫秒）
     * @param float $backoffMultiplier 退避乘数
     */
    public function __construct(
        int $maxRetries = 3,
        int $initialBackoff = 100,
        float $backoffMultiplier = 2.0
    ) {
        $this->maxRetries = $maxRetries;
        $this->initialBackoff = $initialBackoff;
        $this->backoffMultiplier = $backoffMultiplier;
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
        $retryCount = 0;
        $backoff = $this->initialBackoff;

        while (true) {
            try {
                return $next($request);
            } catch (\Throwable $e) {
                $retryCount++;

                if ($retryCount > $this->maxRetries) {
                    throw $e;
                }

                $jitter = random_int(0, (int) ($backoff * 0.1));
                $delay = $backoff + $jitter;

                usleep((int) ($delay * 1000));

                $backoff = (int) ($backoff * $this->backoffMultiplier);

                Context::setRetryCount($retryCount);
            }
        }
    }
}
