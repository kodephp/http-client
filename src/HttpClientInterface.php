<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Exception\HttpException;
use Kode\HttpClient\Response\HttpResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP 客户端接口
 *
 * 在 PSR-18 之上补充了一层「开箱即用」的便捷 API：
 * 语义化方法（get/post/...）、批量并发请求，以及带便捷方法的响应对象。
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
interface HttpClientInterface extends ClientInterface
{
    /**
     * 发送 HTTP 请求（PSR-18 标准入口）
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface;

    /**
     * 以语义化方式发送请求
     *
     * @param string $method HTTP 方法
     * @param string|UriInterface $uri 请求 URI（支持相对于 baseUri 的相对路径）
     * @param array<string, mixed> $options 请求选项，详见 {@see \Kode\HttpClient\Request\RequestBuilder}
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    public function request(string $method, string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 GET 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function get(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 POST 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function post(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 PUT 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function put(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 PATCH 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function patch(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 DELETE 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function delete(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 HEAD 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function head(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 发送 OPTIONS 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    public function options(string|UriInterface $uri, array $options = []): HttpResponse;

    /**
     * 批量发送请求
     *
     * 语义为「全部落定」：单个请求失败不会中断整批，失败项以 Throwable 形式返回，
     * 结果数组的键与入参保持一致。
     *
     * @param array<array-key, RequestInterface> $requests 请求集合
     * @return array<array-key, HttpResponse|\Throwable> 结果集合
     */
    public function sendConcurrent(array $requests): array;
}
