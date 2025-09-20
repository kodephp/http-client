<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * 网络异常
 */
class NetworkException extends HttpException implements NetworkExceptionInterface
{
    /**
     * @var RequestInterface 请求对象
     */
    private RequestInterface $request;

    /**
     * 构造函数
     *
     * @param string $message 异常消息
     * @param RequestInterface $request 请求对象
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $message, RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    /**
     * 获取请求对象
     *
     * @return RequestInterface 请求对象
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}