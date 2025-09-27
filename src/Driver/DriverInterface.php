<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\HttpException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 驱动接口
 *
 * 定义底层 HTTP 驱动的统一接口
 */
interface DriverInterface
{
    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param Context $context 请求上下文
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function sendRequest(RequestInterface $request, Context $context): ResponseInterface;
}