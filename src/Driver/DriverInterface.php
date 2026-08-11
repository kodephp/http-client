<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Exception\HttpException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 驱动接口
 *
 * 定义底层 HTTP 驱动的统一接口，支持多种运行时环境
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface DriverInterface
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
