<?php

declare(strict_types=1);

namespace Kode\HttpClient\Context;

use Kode\Context\Context as BaseContext;

/**
 * HTTP 客户端上下文类
 * 
 * 扩展基础上下文类，添加 HTTP 客户端特定的功能
 */
class Context extends BaseContext
{
    public const TIMEOUT_KEY = 'http_timeout';
    public const RETRY_KEY = 'http_retry_count';

    /**
     * 获取超时时间
     *
     * @return float|null 超时时间（秒），如果未设置则返回 null
     */
    public function getTimeout(): ?float
    {
        $timeout = self::get(self::TIMEOUT_KEY);
        return $timeout !== null ? (float) $timeout : null;
    }

    /**
     * 设置超时时间并返回新的上下文实例
     *
     * @param float $timeout 超时时间（秒）
     * @return self 新的上下文实例
     */
    public function withTimeout(float $timeout): self
    {
        $newContext = clone $this;
        self::set(self::TIMEOUT_KEY, $timeout);
        return $newContext;
    }

    /**
     * 获取重试次数
     *
     * @return int|null 重试次数，如果未设置则返回 null
     */
    public function getRetryCount(): ?int
    {
        $retryCount = self::get(self::RETRY_KEY);
        return $retryCount !== null ? (int) $retryCount : null;
    }

    /**
     * 设置重试次数并返回新的上下文实例
     *
     * @param int $retryCount 重试次数
     * @return self 新的上下文实例
     */
    public function withRetryCount(int $retryCount): self
    {
        $newContext = clone $this;
        self::set(self::RETRY_KEY, $retryCount);
        return $newContext;
    }
}