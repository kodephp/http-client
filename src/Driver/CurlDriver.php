<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Curl HTTP 驱动
 *
 * 基于 PHP curl 扩展实现的同步 HTTP 驱动
 * 支持 PHP 8.1+ 并兼容 PHP 8.5 新特性
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class CurlDriver implements DriverInterface
{
    /**
     * 默认超时时间（秒）
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * 默认连接超时时间（秒）
     */
    private const DEFAULT_CONNECT_TIMEOUT = 10;

    /**
     * 默认请求头
     */
    private const DEFAULT_HEADERS = [
        'Accept' => '*/*',
        'User-Agent' => 'KodeHttpClient/2.0',
    ];

    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当发生网络错误时抛出
     * @throws RequestException 当请求格式错误时抛出
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!extension_loaded('curl')) {
            throw new NetworkException('cURL 扩展未加载', $request);
        }

        $ch = curl_init();

        if ($ch === false) {
            throw new NetworkException('无法初始化 cURL 会话', $request);
        }

        try {
            $this->configureCurl($ch, $request);

            $response = curl_exec($ch);

            if ($response === false) {
                $error = curl_error($ch);
                $errno = curl_errno($ch);
                throw new NetworkException(
                    sprintf('cURL 错误 [%d]: %s', $errno, $error),
                    $request
                );
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);

            $headers = $this->parseHeaders($responseHeaders);

            return new Response($statusCode, $headers, $responseBody);

        } finally {
            curl_close($ch);
        }
    }

    /**
     * 配置 cURL 句柄
     *
     * @param resource $ch cURL 句柄
     * @param RequestInterface $request PSR-7 请求对象
     */
    private function configureCurl($ch, RequestInterface $request): void
    {
        $uri = $request->getUri();

        curl_setopt($ch, CURLOPT_URL, (string) $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());

        $headers = $this->buildHeaders($request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = (string) $request->getBody();
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $timeout = Context::getTimeout() ?? self::DEFAULT_TIMEOUT;
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::DEFAULT_CONNECT_TIMEOUT);

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($uri->getScheme() === 'https') {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }
    }

    /**
     * 构建请求头数组
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return array<int, string> 请求头数组
     */
    private function buildHeaders(RequestInterface $request): array
    {
        $headers = [];

        foreach (self::DEFAULT_HEADERS as $name => $value) {
            if (!$request->hasHeader($name)) {
                $headers[] = $name . ': ' . $value;
            }
        }

        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) === 'host') {
                continue;
            }
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $host = $request->getHeaderLine('Host');
        if ($host === '') {
            $uri = $request->getUri();
            $host = $uri->getHost();
            if ($port = $uri->getPort()) {
                $host .= ':' . $port;
            }
        }
        if ($host !== '') {
            $headers[] = 'Host: ' . $host;
        }

        return $headers;
    }

    /**
     * 解析响应头
     *
     * @param string $headerContent 响应头内容
     * @return array<string, array<int, string>> 解析后的响应头
     */
    private function parseHeaders(string $headerContent): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerContent);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (!isset($headers[$key])) {
                $headers[$key] = [];
            }
            $headers[$key][] = $value;
        }

        return $headers;
    }
}
