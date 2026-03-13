<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 限流中间件
 *
 * 实现请求频率限制，使用令牌桶算法
 * 支持阻塞等待和非阻塞模式
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 桶的容量
     */
    private readonly int $capacity;

    /**
     * 令牌生成速率（每秒生成的令牌数）
     */
    private readonly int $rate;

    /**
     * 当前令牌数
     */
    private float $tokens;

    /**
     * 上次更新时间
     */
    private float $lastUpdate;

    /**
     * 是否阻塞等待
     */
    private readonly bool $blocking;

    /**
     * 构造函数
     *
     * @param int $capacity 桶的容量
     * @param int $rate 令牌生成速率（每秒生成的令牌数）
     * @param bool $blocking 是否阻塞等待
     */
    public function __construct(int $capacity = 10, int $rate = 1, bool $blocking = false)
    {
        $this->capacity = $capacity;
        $this->rate = $rate;
        $this->tokens = $capacity;
        $this->lastUpdate = microtime(true);
        $this->blocking = $blocking;
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
        $this->updateTokens();

        if ($this->tokens < 1) {
            if ($this->blocking) {
                $waitTime = (1 - $this->tokens) / $this->rate;
                usleep((int) ($waitTime * 1000000));
                $this->updateTokens();
            } else {
                throw new \RuntimeException('请求频率超限，请稍后重试');
            }
        }

        $this->tokens--;

        return $next($request);
    }

    /**
     * 更新令牌数
     */
    private function updateTokens(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastUpdate;
        $this->lastUpdate = $now;

        $newTokens = $elapsed * $this->rate;
        $this->tokens = min($this->capacity, $this->tokens + $newTokens);
    }

    /**
     * 获取当前令牌数
     *
     * @return float 当前令牌数
     */
    public function getTokens(): float
    {
        $this->updateTokens();
        return $this->tokens;
    }
}
