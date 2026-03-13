<?php

declare(strict_types=1);

namespace Kode\HttpClient\Context;

use Kode\Context\Context as BaseContext;

/**
 * HTTP 客户端上下文辅助类
 *
 * 提供 HTTP 客户端特定的上下文操作方法
 * 由于 BaseContext 是 final 类，此类提供静态辅助方法
 *
 * @package Kode\HttpClient\Context
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class Context
{
    /**
     * 超时时间键名
     */
    public const TIMEOUT_KEY = 'http_timeout';

    /**
     * 重试次数键名
     */
    public const RETRY_KEY = 'http_retry_count';

    /**
     * 请求开始时间键名
     */
    public const START_TIME_KEY = 'http_start_time';

    /**
     * 请求 ID 键名
     */
    public const REQUEST_ID_KEY = 'http_request_id';

    /**
     * 私有构造函数，防止实例化
     */
    private function __construct()
    {
    }

    /**
     * 获取超时时间
     *
     * @return float|null 超时时间（秒），如果未设置则返回 null
     */
    public static function getTimeout(): ?float
    {
        $timeout = BaseContext::get(self::TIMEOUT_KEY);
        return $timeout !== null ? (float) $timeout : null;
    }

    /**
     * 设置超时时间
     *
     * @param float $timeout 超时时间（秒）
     */
    public static function setTimeout(float $timeout): void
    {
        BaseContext::set(self::TIMEOUT_KEY, $timeout);
    }

    /**
     * 获取重试次数
     *
     * @return int 重试次数，如果未设置则返回 0
     */
    public static function getRetryCount(): int
    {
        $retryCount = BaseContext::get(self::RETRY_KEY);
        return $retryCount !== null ? (int) $retryCount : 0;
    }

    /**
     * 设置重试次数
     *
     * @param int $retryCount 重试次数
     */
    public static function setRetryCount(int $retryCount): void
    {
        BaseContext::set(self::RETRY_KEY, $retryCount);
    }

    /**
     * 增加重试次数
     *
     * @return int 增加后的重试次数
     */
    public static function incrementRetryCount(): int
    {
        $count = self::getRetryCount() + 1;
        self::setRetryCount($count);
        return $count;
    }

    /**
     * 获取请求开始时间
     *
     * @return float|null 请求开始时间（微秒），如果未设置则返回 null
     */
    public static function getStartTime(): ?float
    {
        return BaseContext::get(self::START_TIME_KEY);
    }

    /**
     * 设置请求开始时间
     *
     * @param float $startTime 请求开始时间（微秒）
     */
    public static function setStartTime(float $startTime): void
    {
        BaseContext::set(self::START_TIME_KEY, $startTime);
    }

    /**
     * 获取请求 ID
     *
     * @return string|null 请求 ID，如果未设置则返回 null
     */
    public static function getRequestId(): ?string
    {
        return BaseContext::get(self::REQUEST_ID_KEY);
    }

    /**
     * 设置请求 ID
     *
     * @param string $requestId 请求 ID
     */
    public static function setRequestId(string $requestId): void
    {
        BaseContext::set(self::REQUEST_ID_KEY, $requestId);
    }

    /**
     * 初始化 HTTP 上下文
     *
     * @param array<string, mixed> $options 配置选项
     * @return string 生成的请求 ID
     */
    public static function initialize(array $options = []): string
    {
        if (isset($options['timeout'])) {
            self::setTimeout((float) $options['timeout']);
        }

        if (isset($options['retry_count'])) {
            self::setRetryCount((int) $options['retry_count']);
        }

        self::setStartTime(microtime(true));

        $requestId = bin2hex(random_bytes(16));
        self::setRequestId($requestId);

        return $requestId;
    }

    /**
     * 清除 HTTP 上下文
     */
    public static function clear(): void
    {
        BaseContext::delete(self::TIMEOUT_KEY);
        BaseContext::delete(self::RETRY_KEY);
        BaseContext::delete(self::START_TIME_KEY);
        BaseContext::delete(self::REQUEST_ID_KEY);
    }

    /**
     * 获取请求耗时（毫秒）
     *
     * @return float|null 请求耗时（毫秒），如果未设置开始时间则返回 null
     */
    public static function getElapsedTime(): ?float
    {
        $startTime = self::getStartTime();
        if ($startTime === null) {
            return null;
        }

        return (microtime(true) - $startTime) * 1000;
    }

    /**
     * 导出 HTTP 上下文数据
     *
     * @return array<string, mixed> HTTP 上下文数据
     */
    public static function export(): array
    {
        return BaseContext::export([
            self::TIMEOUT_KEY,
            self::RETRY_KEY,
            self::START_TIME_KEY,
            self::REQUEST_ID_KEY,
        ]);
    }

    /**
     * 导入 HTTP 上下文数据
     *
     * @param array<string, mixed> $data HTTP 上下文数据
     */
    public static function import(array $data): void
    {
        BaseContext::import($data, true);
    }
}
