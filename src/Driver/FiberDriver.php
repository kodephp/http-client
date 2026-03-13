<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Fiber;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Fiber HTTP 驱动
 *
 * 基于 PHP 8.1+ Fiber 实现的异步 HTTP 驱动
 * 支持 kode/fibers 包集成
 * 支持 PHP 8.5 新特性
 *
 * @package Kode\HttpClient\Driver
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class FiberDriver implements DriverInterface
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
     * Curl 多句柄（用于并发请求）
     */
    private ?\CurlMultiHandle $multiHandle = null;

    /**
     * 活跃的 Curl 句柄
     *
     * @var array<int, \CurlHandle>
     */
    private array $activeHandles = [];

    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当发生网络错误时抛出
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!extension_loaded('curl')) {
            throw new NetworkException('cURL 扩展未加载', $request);
        }

        if (!class_exists(Fiber::class)) {
            throw new NetworkException('PHP Fiber 不支持，需要 PHP 8.1+', $request);
        }

        return $this->executeInFiber($request);
    }

    /**
     * 在 Fiber 中执行请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     */
    private function executeInFiber(RequestInterface $request): ResponseInterface
    {
        $fiber = new Fiber(function () use ($request): ResponseInterface {
            return $this->executeRequest($request);
        });

        try {
            $response = $fiber->start();

            while (!$fiber->isTerminated()) {
                if ($fiber->isSuspended()) {
                    $this->processMultiHandle();
                    $fiber->resume();
                }
            }

            return $response;
        } catch (\Throwable $e) {
            throw new NetworkException(
                sprintf('Fiber 请求失败: %s', $e->getMessage()),
                $request,
                $e
            );
        }
    }

    /**
     * 执行单个请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     */
    private function executeRequest(RequestInterface $request): ResponseInterface
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new NetworkException('无法初始化 cURL 会话', $request);
        }

        $this->configureCurl($ch, $request);

        $this->initMultiHandle();
        curl_multi_add_handle($this->multiHandle, $ch);
        $this->activeHandles[(int) $ch] = $ch;

        $running = null;
        do {
            $status = curl_multi_exec($this->multiHandle, $running);

            if ($status === CURLM_CALL_MULTI_PERFORM) {
                continue;
            }

            if ($running > 0) {
                $ready = curl_multi_select($this->multiHandle, 0.1);
                if ($ready === -1) {
                    usleep(1000);
                }

                Fiber::suspend('waiting');
            }
        } while ($running > 0 && $status === CURLM_OK);

        $response = $this->extractResponse($ch, $request);

        curl_multi_remove_handle($this->multiHandle, $ch);
        curl_close($ch);
        unset($this->activeHandles[(int) $ch]);

        return $response;
    }

    /**
     * 配置 cURL 句柄
     *
     * @param \CurlHandle $ch cURL 句柄
     * @param RequestInterface $request PSR-7 请求对象
     */
    private function configureCurl(\CurlHandle $ch, RequestInterface $request): void
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
        $headers = [
            'Accept: */*',
            'User-Agent: KodeHttpClient/2.2 (Fiber)',
        ];

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
     * 从 cURL 句柄提取响应
     *
     * @param \CurlHandle $ch cURL 句柄
     * @param RequestInterface $request PSR-7 请求对象
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当请求失败时抛出
     */
    private function extractResponse(\CurlHandle $ch, RequestInterface $request): ResponseInterface
    {
        $error = curl_error($ch);
        if ($error !== '') {
            $errno = curl_errno($ch);
            throw new NetworkException(
                sprintf('cURL 错误 [%d]: %s', $errno, $error),
                $request
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $response = curl_multi_getcontent($ch);
        if ($response === false) {
            throw new NetworkException('无法获取响应内容', $request);
        }

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $headers = $this->parseHeaders($responseHeaders);

        return new Response($statusCode, $headers, $responseBody);
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

    /**
     * 初始化多句柄
     */
    private function initMultiHandle(): void
    {
        if ($this->multiHandle === null) {
            $this->multiHandle = curl_multi_init();
        }
    }

    /**
     * 处理多句柄
     */
    private function processMultiHandle(): void
    {
        if ($this->multiHandle === null) {
            return;
        }

        $running = null;
        do {
            curl_multi_exec($this->multiHandle, $running);
        } while ($running > 0);
    }

    /**
     * 析构函数 - 清理资源
     */
    public function __destruct()
    {
        foreach ($this->activeHandles as $ch) {
            curl_close($ch);
        }

        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
        }
    }
}
