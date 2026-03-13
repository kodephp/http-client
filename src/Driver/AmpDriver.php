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
 * Amp HTTP 驱动
 *
 * 基于 amphp/http-client 实现的异步 HTTP 驱动
 * 支持 Amp 4.0+ 版本
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class AmpDriver implements DriverInterface
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
        if (!class_exists(\Amp\Http\Client\HttpClient::class)) {
            throw new NetworkException('amphp/http-client 包未安装', $request);
        }

        try {
            return $this->executeAmpRequest($request);
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Amp 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        }
    }

    /**
     * 执行 Amp HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     */
    private function executeAmpRequest(RequestInterface $request): ResponseInterface
    {
        $client = \Amp\Http\Client\HttpClientBuilder::buildDefault();

        $ampRequest = $this->createAmpRequest($request);

        $response = \Amp\Future\await($client->request($ampRequest));

        return $this->createPsrResponse($response);
    }

    /**
     * 创建 Amp 请求对象
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return \Amp\Http\Client\Request Amp 请求对象
     */
    private function createAmpRequest(RequestInterface $request): \Amp\Http\Client\Request
    {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();

        $ampRequest = new \Amp\Http\Client\Request($uri, $method);

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $ampRequest->addHeader($name, $value);
            }
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            $ampRequest->setBody($body);
        }

        $timeout = Context::getTimeout() ?? self::DEFAULT_TIMEOUT;
        $ampRequest->setInactivityTimeout((int) ($timeout * 1000));
        $ampRequest->setTransferTimeout((int) ($timeout * 1000));

        return $ampRequest;
    }

    /**
     * 创建 PSR-7 响应对象
     *
     * @param \Amp\Http\Client\Response $response Amp 响应对象
     * @return ResponseInterface PSR-7 响应对象
     */
    private function createPsrResponse(\Amp\Http\Client\Response $response): ResponseInterface
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        $body = \Amp\Future\await($response->getBody()->buffer());

        return new Response(
            $response->getStatus(),
            $headers,
            $body
        );
    }
}
