<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\ConfigurationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 超时中间件
 *
 * 把超时配置写入请求上下文，供下游驱动读取。
 *
 * v2.4 增强：
 *  - 支持连接超时（connectTimeout）
 *  - 请求结束后恢复原有上下文，避免超时配置在协程/常驻进程中互相污染
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class TimeoutMiddleware implements MiddlewareInterface
{
    /**
     * 构造函数
     *
     * @param float $defaultTimeout 默认总超时时间（秒）
     * @param float|null $connectTimeout 连接超时时间（秒），null 表示沿用现有配置
     */
    public function __construct(
        private readonly float $defaultTimeout = 30.0,
        private readonly ?float $connectTimeout = null,
    ) {
        if ($defaultTimeout < 0) {
            throw new ConfigurationException('defaultTimeout 不能为负数');
        }

        if ($connectTimeout !== null && $connectTimeout < 0) {
            throw new ConfigurationException('connectTimeout 不能为负数');
        }
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
        $previousTimeout = Context::getTimeout();
        $previousTransport = Context::rawTransportOptions();

        $timeout = $previousTimeout ?? $this->defaultTimeout;

        $overrides = ['timeout' => $timeout];
        if ($this->connectTimeout !== null) {
            $overrides['connect_timeout'] = $this->connectTimeout;
        }

        Context::setTimeout($timeout);
        Context::setTransportOptions(Context::getTransportOptions()->with($overrides));

        try {
            return $next($request);
        } finally {
            Context::setTransportOptions($previousTransport);

            if ($previousTimeout !== null) {
                Context::setTimeout($previousTimeout);
            } else {
                Context::clearTimeout();
            }
        }
    }

    /**
     * 获取默认超时配置
     */
    public function defaultOptions(): TransportOptions
    {
        return TransportOptions::fromArray([
            'timeout' => $this->defaultTimeout,
            'connect_timeout' => $this->connectTimeout ?? 10.0,
        ]);
    }
}
