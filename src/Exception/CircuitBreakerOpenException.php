<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * 熔断器开启异常
 *
 * 当目标服务连续失败达到阈值、熔断器处于 OPEN 状态时，
 * 请求会被立即拒绝（快速失败），不再打到下游。
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CircuitBreakerOpenException extends \RuntimeException implements ClientExceptionInterface
{
    /**
     * 熔断作用域标识（通常为 scheme://host:port）
     */
    public readonly string $scope;

    /**
     * 距离进入半开状态的剩余秒数
     */
    public readonly float $retryAfter;

    /**
     * 构造函数
     *
     * @param string $scope 熔断作用域标识
     * @param float $retryAfter 距离进入半开状态的剩余秒数
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $scope, float $retryAfter, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('熔断器已开启（%s），%.1f 秒后可重试', $scope, $retryAfter),
            0,
            $previous
        );

        $this->scope = $scope;
        $this->retryAfter = $retryAfter;
    }
}
