<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * HTTP 异常基类
 *
 * 所有 HTTP 客户端异常的基类
 * 遵循 PSR-18 规范
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
class HttpException extends \Exception implements ClientExceptionInterface
{
    /**
     * 请求 URI
     */
    protected ?string $requestUri = null;

    /**
     * 设置请求 URI
     *
     * @param string $uri 请求 URI
     */
    public function setRequestUri(string $uri): void
    {
        $this->requestUri = $uri;
    }

    /**
     * 获取请求 URI
     *
     * @return string|null 请求 URI
     */
    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }
}
