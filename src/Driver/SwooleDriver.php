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
 * Swoole HTTP 驱动
 *
 * 基于 Swoole 协程实现的高性能 HTTP 驱动
 * 支持 Swoole 5.0+ 版本
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class SwooleDriver implements DriverInterface
{
    /**
     * 默认超时时间（秒）
     */
    private const DEFAULT_TIMEOUT = 30;

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
        if (!extension_loaded('swoole')) {
            throw new NetworkException('Swoole 扩展未加载', $request);
        }

        $cid = \Swoole\Coroutine::getCid();
        if ($cid < 0) {
            throw new NetworkException('Swoole 驱动必须在协程环境中使用', $request);
        }

        $client = $this->createClient($request);

        try {
            $response = $this->executeRequest($client, $request);
            return $this->createResponse($response);
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Swoole 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        } finally {
            $client->close();
        }
    }

    /**
     * 创建 Swoole HTTP 客户端
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return \Swoole\Coroutine\Http\Client
     */
    private function createClient(RequestInterface $request): \Swoole\Coroutine\Http\Client
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $ssl = $uri->getScheme() === 'https';

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);

        $timeout = Context::getTimeout() ?? self::DEFAULT_TIMEOUT;
        $client->setHeaders([
            'Host' => $host,
            'User-Agent' => 'KodeHttpClient/2.0',
        ]);
        $client->set(['timeout' => $timeout]);

        return $client;
    }

    /**
     * 执行 HTTP 请求
     *
     * @param \Swoole\Coroutine\Http\Client $client Swoole 客户端
     * @param RequestInterface $request PSR-7 请求对象
     * @return \Swoole\Http\Response
     *
     * @throws NetworkException 当请求失败时抛出
     */
    private function executeRequest(
        \Swoole\Coroutine\Http\Client $client,
        RequestInterface $request
    ): \Swoole\Http\Response {
        $uri = $request->getUri();
        $path = $uri->getPath() ?: '/';
        $query = $uri->getQuery();
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $client->setHeaders($headers);

        $body = (string) $request->getBody();
        $method = $request->getMethod();

        $client->setMethod($method);

        if ($body !== '') {
            $client->setData($body);
        }

        $success = $client->execute($path);

        if (!$success) {
            throw new NetworkException(
                sprintf('Swoole 请求执行失败: %s', $client->errMsg ?? '未知错误'),
                $request
            );
        }

        return $client;
    }

    /**
     * 创建 PSR-7 响应对象
     *
     * @param \Swoole\Coroutine\Http\Client $client Swoole 客户端
     * @return ResponseInterface PSR-7 响应对象
     */
    private function createResponse(\Swoole\Coroutine\Http\Client $client): ResponseInterface
    {
        $headers = [];
        if (property_exists($client, 'headers') && is_array($client->headers)) {
            foreach ($client->headers as $name => $value) {
                $headers[$name] = [$value];
            }
        }

        $body = '';
        if (property_exists($client, 'body')) {
            $body = (string) $client->body;
        }

        $statusCode = $client->statusCode ?? 200;

        return new Response($statusCode, $headers, $body);
    }
}
