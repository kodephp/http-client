<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver;

use Kode\Context\Context;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Curl HTTP 驱动
 *
 * 基于 PHP curl 扩展实现的同步 HTTP 驱动
 */
class CurlDriver implements DriverInterface
{
    /**
     * 发送 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param Context $context 请求上下文
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws NetworkException 当发生网络错误时抛出
     * @throws RequestException 当请求格式错误时抛出
     */
    public function sendRequest(RequestInterface $request, Context $context): ResponseInterface
    {
        // 检查 curl 扩展是否可用
        if (!extension_loaded('curl')) {
            throw new NetworkException('cURL extension is not loaded', $request);
        }

        // 初始化 curl 句柄
        $ch = curl_init();
        
        if ($ch === false) {
            throw new NetworkException('Failed to initialize cURL session', $request);
        }

        try {
            // 设置请求 URL
            curl_setopt($ch, CURLOPT_URL, (string) $request->getUri());
            
            // 设置请求方法
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());
            
            // 设置请求头
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $headers[] = $name . ': ' . $value;
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            // 设置请求体
            $body = (string) $request->getBody();
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            
            // 设置返回响应而不是直接输出
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // 设置返回响应头
            curl_setopt($ch, CURLOPT_HEADER, true);
            
            // 设置超时
            $timeout = $context->getTimeout();
            if ($timeout !== null) {
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            }
            
            // 执行请求
            $response = curl_exec($ch);
            
            // 检查是否有错误
            if ($response === false) {
                $error = curl_error($ch);
                throw new NetworkException('cURL error: ' . $error, $request);
            }
            
            // 获取 HTTP 状态码
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // 获取响应头大小
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            
            // 分离响应头和响应体
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);
            
            // 解析响应头
            $headers = [];
            $lines = explode("\r\n", $responseHeaders);
            foreach ($lines as $line) {
                if (strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!isset($headers[$key])) {
                        $headers[$key] = [];
                    }
                    $headers[$key][] = $value;
                }
            }
            
            // 创建响应对象
            return new Response($statusCode, $headers, $responseBody);
            
        } finally {
            // 关闭 curl 句柄
            curl_close($ch);
        }
    }
}