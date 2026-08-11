<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Exception\RateLimitException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 限流中间件
 *
 * 令牌桶算法实现的客户端侧限流，支持阻塞等待与快速失败两种模式。
 *
 * v2.4 增强：
 *  - 抛出语义明确的 RateLimitException（仍继承 \RuntimeException，保持向后兼容）
 *  - 令牌速率支持小数（例如每 2 秒 1 个请求可写作 rate = 0.5）
 *  - 阻塞模式支持最长等待时间，避免无限期挂起
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 当前令牌数
     */
    private float $tokens;

    /**
     * 上次更新时间
     */
    private float $lastUpdate;

    /**
     * 睡眠函数（秒）
     *
     * @var \Closure(float): void
     */
    private readonly \Closure $sleeper;

    /**
     * 构造函数
     *
     * @param int $capacity 桶容量
     * @param float $rate 令牌生成速率（个/秒）
     * @param bool $blocking 令牌不足时是否阻塞等待
     * @param float $maxWait 阻塞模式下的最长等待时间（秒），0 表示不限制
     * @param (callable(float): void)|null $sleeper 自定义睡眠函数，主要用于测试
     */
    public function __construct(
        private readonly int $capacity = 10,
        private readonly float $rate = 1.0,
        private readonly bool $blocking = false,
        private readonly float $maxWait = 0.0,
        ?callable $sleeper = null,
    ) {
        if ($capacity <= 0) {
            throw new ConfigurationException('capacity 必须大于 0');
        }

        if ($rate <= 0) {
            throw new ConfigurationException('rate 必须大于 0');
        }

        $this->tokens = (float) $capacity;
        $this->lastUpdate = microtime(true);
        $this->sleeper = $sleeper !== null
            ? \Closure::fromCallable($sleeper)
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            };
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws RateLimitException 当令牌不足且处于非阻塞模式时抛出
     */
    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $this->updateTokens();

        if ($this->tokens < 1.0) {
            $waitTime = (1.0 - $this->tokens) / $this->rate;

            if (!$this->blocking) {
                throw new RateLimitException(
                    sprintf('请求频率超限，请在 %.3f 秒后重试', $waitTime),
                    $waitTime
                );
            }

            if ($this->maxWait > 0 && $waitTime > $this->maxWait) {
                throw new RateLimitException(
                    sprintf('等待令牌所需时间 %.3f 秒超过上限 %.3f 秒', $waitTime, $this->maxWait),
                    $waitTime
                );
            }

            ($this->sleeper)($waitTime);
            $this->updateTokens();
        }

        $this->tokens = max(0.0, $this->tokens - 1.0);

        return $next($request);
    }

    /**
     * 按经过的时间补充令牌
     */
    private function updateTokens(): void
    {
        $now = microtime(true);
        $elapsed = max(0.0, $now - $this->lastUpdate);
        $this->lastUpdate = $now;

        $this->tokens = min((float) $this->capacity, $this->tokens + $elapsed * $this->rate);
    }

    /**
     * 获取当前可用令牌数
     */
    public function getTokens(): float
    {
        $this->updateTokens();

        return $this->tokens;
    }

    /**
     * 获取桶容量
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }
}
