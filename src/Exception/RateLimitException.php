<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * 限流异常
 *
 * 当本地令牌桶耗尽且处于非阻塞模式时抛出。
 * 继承 \RuntimeException 以保持与 2.3 及更早版本的捕获行为兼容。
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RateLimitException extends \RuntimeException implements ClientExceptionInterface
{
    /**
     * 建议的重试等待时间（秒）
     */
    public readonly float $retryAfter;

    /**
     * 构造函数
     *
     * @param string $message 异常消息
     * @param float $retryAfter 建议的重试等待时间（秒）
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(
        string $message = '请求频率超限，请稍后重试',
        float $retryAfter = 0.0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->retryAfter = $retryAfter;
    }
}
