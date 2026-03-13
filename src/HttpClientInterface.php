<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Exception\HttpException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 客户端接口
 *
 * 提供统一的 HTTP 客户端抽象，遵循 PSR-18 规范
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
interface HttpClientInterface
{
    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function sendRequest(RequestInterface $request): ResponseInterface;
}
