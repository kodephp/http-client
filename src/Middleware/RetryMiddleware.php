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
 */
class RetryMiddleware implements MiddlewareInterface
{
    /**
     * @var int 最大重试次数
     */
    private int $maxRetries;

    /**
     * @var int 初始退避时间（毫秒）
     */
    private int $initialBackoff;

    /**
     * @var float 退避乘数
     */
    private float $backoffMultiplier;

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
 * @param RequestInterface $request 请求对象
 * @param Context $context 请求上下文
 * @param callable $next 下一个中间件
 * @return ResponseInterface 响应对象
 */
public function process(RequestInterface $request, Context $context, callable $next): ResponseInterface
{
    $retryCount = 0;
    $backoff = $this->initialBackoff;

    while (true) {
        try {
            return $next($request, $context);
        } catch (NetworkException $e) {
            // 检查是否达到最大重试次数
            if ($retryCount >= $this->maxRetries) {
                throw $e;
            }

            // 增加重试计数
            $retryCount++;

            // 计算退避时间（添加随机抖动）
                $jitter = mt_rand(0, (int)($backoff * 0.1));
                $delay = $backoff + $jitter;

                // 等待退避时间
                usleep((int)($delay * 1000));

            // 增加下次退避时间
            $backoff *= $this->backoffMultiplier;
        } catch (\Exception $e) {
            // 检查是否达到最大重试次数
            if ($retryCount >= $this->maxRetries) {
                throw $e;
            }

            // 增加重试计数
            $retryCount++;

            // 计算退避时间（添加随机抖动）
            $jitter = mt_rand(0, (int)($backoff * 0.1));
            $delay = $backoff + $jitter;

            // 等待退避时间
            usleep((int)($delay * 1000));

            // 增加下次退避时间
            $backoff *= $this->backoffMultiplier;
        }
    }
}
}