<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\CircuitBreakerOpenException;
use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Exception\RateLimitException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 重试中间件
 *
 * v2.4 重写要点（此前的实现存在明显风险）：
 *  - 旧版对任意 Throwable 都重试，包括参数错误、限流拒绝等本不该重试的异常；
 *    新版默认只重试「网络类异常」与「可重试状态码」
 *  - 旧版会对 POST 等非幂等请求盲目重试，可能造成重复下单等副作用；
 *    新版默认只重试幂等方法，可通过 retryNonIdempotent 显式放开
 *  - 旧版退避时间指数增长且无上限；新版提供 maxBackoff 封顶
 *  - 新增对响应头 Retry-After 的支持（秒数与 HTTP 日期两种格式）
 *  - 支持注入自定义决策器与睡眠函数（便于单元测试）
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RetryMiddleware implements MiddlewareInterface
{
    /**
     * 默认可重试的状态码
     *
     * @var list<int>
     */
    public const array DEFAULT_RETRY_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * 幂等的 HTTP 方法
     *
     * @var list<string>
     */
    public const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /**
     * 永不重试的异常类型
     *
     * @var list<class-string>
     */
    private const array NON_RETRYABLE_EXCEPTIONS = [
        ConfigurationException::class,
        RateLimitException::class,
        CircuitBreakerOpenException::class,
    ];

    /**
     * 自定义重试决策器
     *
     * @var (\Closure(RequestInterface, ?ResponseInterface, ?\Throwable, int): ?bool)|null
     */
    private readonly ?\Closure $decider;

    /**
     * 睡眠函数（秒）
     *
     * @var \Closure(float): void
     */
    private readonly \Closure $sleeper;

    /**
     * 构造函数
     *
     * @param int $maxRetries 最大重试次数（不含首次请求）
     * @param int $initialBackoff 初始退避时间（毫秒）
     * @param float $backoffMultiplier 退避乘数
     * @param int $maxBackoff 单次退避上限（毫秒）
     * @param list<int> $retryStatusCodes 可重试的响应状态码
     * @param bool $retryNonIdempotent 是否对非幂等方法（POST/PATCH）也重试
     * @param bool $respectRetryAfter 是否遵循响应头 Retry-After
     * @param (callable(RequestInterface, ?ResponseInterface, ?\Throwable, int): ?bool)|null $decider
     *        自定义决策器，返回 null 表示交回默认策略
     * @param (callable(float): void)|null $sleeper 自定义睡眠函数（秒），主要用于测试
     */
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $initialBackoff = 100,
        private readonly float $backoffMultiplier = 2.0,
        private readonly int $maxBackoff = 10000,
        private readonly array $retryStatusCodes = self::DEFAULT_RETRY_STATUS_CODES,
        private readonly bool $retryNonIdempotent = false,
        private readonly bool $respectRetryAfter = true,
        ?callable $decider = null,
        ?callable $sleeper = null,
    ) {
        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries 不能为负数');
        }

        $this->decider = $decider !== null ? \Closure::fromCallable($decider) : null;
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
     */
    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $response = null;
            $error = null;

            try {
                $response = $next($request);
            } catch (\Throwable $e) {
                $error = $e;
            }

            $shouldRetry = $this->shouldRetry($request, $response, $error, $attempt);

            if (!$shouldRetry || $attempt >= $this->maxRetries) {
                if ($error !== null) {
                    throw $error;
                }

                /** @var ResponseInterface $response */
                return $response;
            }

            $attempt++;
            Context::setRetryCount($attempt);

            ($this->sleeper)($this->computeDelay($attempt, $response));
        }
    }

    /**
     * 判定是否需要重试
     *
     * @param RequestInterface $request 请求对象
     * @param ResponseInterface|null $response 响应对象（异常时为 null）
     * @param \Throwable|null $error 捕获到的异常
     * @param int $attempt 已完成的重试次数
     */
    private function shouldRetry(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?\Throwable $error,
        int $attempt
    ): bool {
        if ($this->decider !== null) {
            $decision = ($this->decider)($request, $response, $error, $attempt);
            if ($decision !== null) {
                return $decision;
            }
        }

        if (!$this->retryNonIdempotent && !$this->isIdempotent($request)) {
            return false;
        }

        if ($error !== null) {
            foreach (self::NON_RETRYABLE_EXCEPTIONS as $class) {
                if ($error instanceof $class) {
                    return false;
                }
            }

            return $error instanceof NetworkExceptionInterface;
        }

        return $response !== null
            && in_array($response->getStatusCode(), $this->retryStatusCodes, true);
    }

    /**
     * 请求方法是否幂等
     *
     * @param RequestInterface $request 请求对象
     */
    private function isIdempotent(RequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::IDEMPOTENT_METHODS, true);
    }

    /**
     * 计算本次重试前的等待时长（秒）
     *
     * @param int $attempt 当前是第几次重试（从 1 开始）
     * @param ResponseInterface|null $response 上一次的响应
     */
    private function computeDelay(int $attempt, ?ResponseInterface $response): float
    {
        if ($this->respectRetryAfter && $response !== null) {
            $retryAfter = $this->parseRetryAfter($response);
            if ($retryAfter !== null) {
                return min($retryAfter, $this->maxBackoff / 1000);
            }
        }

        $backoff = $this->initialBackoff * $this->backoffMultiplier ** ($attempt - 1);
        $backoff = min($backoff, (float) $this->maxBackoff);

        // 抖动，避免大量客户端同时重试形成尖峰
        $jitter = $backoff > 0 ? random_int(0, (int) round($backoff * 0.1)) : 0;

        return ($backoff + $jitter) / 1000;
    }

    /**
     * 解析 Retry-After 响应头
     *
     * 同时支持「秒数」与「HTTP 日期」两种格式。
     *
     * @param ResponseInterface $response 响应对象
     * @return float|null 等待秒数，无法解析时返回 null
     */
    private function parseRetryAfter(ResponseInterface $response): ?float
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - time()));
    }
}
