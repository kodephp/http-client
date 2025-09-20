<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * HTTP 异常基类
 */
class HttpException extends \Exception implements ClientExceptionInterface
{
    /**
     * @var string|null 请求 URI
     */
    private ?string $requestUri = null;

    /**
     * 设置请求 URI
     *
     * @param string $uri 请求 URI
     * @return void
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