<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * 超时异常
 *
 * 当请求在设定的超时时间内未完成时抛出。
 * 属于网络异常的一种，可被重试中间件识别为可重试错误。
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class TimeoutException extends HttpException implements NetworkExceptionInterface
{
    /**
     * 请求对象
     */
    private readonly RequestInterface $request;

    /**
     * 触发超时的时长（秒）
     */
    public readonly float $timeout;

    /**
     * 构造函数
     *
     * @param string $message 异常消息
     * @param RequestInterface $request 请求对象
     * @param float $timeout 触发超时的时长（秒）
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(
        string $message,
        RequestInterface $request,
        float $timeout = 0.0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
        $this->timeout = $timeout;
        $this->requestUri = (string) $request->getUri();
    }

    /**
     * 获取请求对象
     */
    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
