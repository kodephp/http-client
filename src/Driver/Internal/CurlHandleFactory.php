<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver\Internal;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Exception\HttpException;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\TimeoutException;
use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * cURL 句柄工厂
 *
 * 将 PSR-7 请求 + 传输配置翻译为完整配置好的 cURL 句柄，
 * 并负责把 cURL 执行结果还原为 PSR-7 响应。
 * CurlDriver 与 FiberDriver 共用此实现，避免逻辑重复与行为漂移。
 *
 * @package Kode\HttpClient\Driver\Internal
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class CurlHandleFactory
{
    /**
     * 判定为超时的 cURL 错误码
     *
     * 使用字面量而非 CURLE_* 常量，避免在 curl 扩展缺失时求值失败。
     * 28 = CURLE_OPERATION_TIMEDOUT
     *
     * @var list<int>
     */
    private const array TIMEOUT_ERRORS = [28];

    /**
     * 私有构造函数，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 创建并配置 cURL 句柄
     *
     * @param RequestInterface $request PSR-7 请求
     * @param TransportOptions $options 传输配置
     * @param HeaderCollector $collector 响应头收集器
     * @param string $userAgentSuffix 附加在 User-Agent 后的标识
     * @return \CurlHandle 已配置的 cURL 句柄
     *
     * @throws NetworkException 当 cURL 会话无法初始化时抛出
     */
    public static function create(
        RequestInterface $request,
        TransportOptions $options,
        HeaderCollector $collector,
        string $userAgentSuffix = ''
    ): \CurlHandle {
        $handle = curl_init();

        if ($handle === false) {
            throw new NetworkException('无法初始化 cURL 会话', $request);
        }

        $collector->reset();

        $uri = $request->getUri();
        $method = strtoupper($request->getMethod());
        $body = (string) $request->getBody();

        $curlOptions = [
            CURLOPT_URL => (string) $uri,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => $collector,
            CURLOPT_HTTPHEADER => self::buildHeaders($request, $options, $userAgentSuffix),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($options->connectTimeout * 1000)),
            CURLOPT_FOLLOWLOCATION => $options->followRedirects,
            CURLOPT_MAXREDIRS => $options->maxRedirects,
            CURLOPT_NOSIGNAL => true,
        ];

        if ($options->timeout > 0) {
            $curlOptions[CURLOPT_TIMEOUT_MS] = max(1, (int) round($options->timeout * 1000));
        }

        // HEAD 必须使用 NOBODY，否则 cURL 会一直等待并不存在的响应体
        if ($method === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
        } else {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        if ($options->decodeContent) {
            // 空字符串表示接受 cURL 支持的全部压缩算法并自动解压
            $curlOptions[CURLOPT_ACCEPT_ENCODING] = '';
        }

        if ($options->verify === false) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        } else {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 2;

            if (is_string($options->verify) && $options->verify !== '') {
                $curlOptions[is_dir($options->verify) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $options->verify;
            }
        }

        if ($uri->getScheme() === 'https') {
            $curlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        if ($options->proxy !== null && $options->proxy !== '') {
            $curlOptions[CURLOPT_PROXY] = $options->proxy;
        }

        // 用户自定义选项拥有最高优先级
        foreach ($options->curlOptions as $option => $value) {
            $curlOptions[$option] = $value;
        }

        curl_setopt_array($handle, $curlOptions);

        return $handle;
    }

    /**
     * 构建 cURL 请求头数组
     *
     * @param RequestInterface $request PSR-7 请求
     * @param TransportOptions $options 传输配置
     * @param string $userAgentSuffix 附加在 User-Agent 后的标识
     * @return list<string> cURL 格式的请求头
     */
    public static function buildHeaders(
        RequestInterface $request,
        TransportOptions $options,
        string $userAgentSuffix = ''
    ): array {
        $userAgent = $options->userAgent;
        if ($userAgentSuffix !== '') {
            $userAgent .= ' (' . $userAgentSuffix . ')';
        }

        $defaults = array_merge(
            ['Accept' => '*/*', 'User-Agent' => $userAgent],
            $options->defaultHeaders
        );

        $headers = [];
        foreach ($defaults as $name => $value) {
            if (!$request->hasHeader($name)) {
                $headers[] = $name . ': ' . $value;
            }
        }

        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Host') === 0) {
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
            $port = $uri->getPort();
            if ($port !== null) {
                $host .= ':' . $port;
            }
        }

        if ($host !== '') {
            $headers[] = 'Host: ' . $host;
        }

        return $headers;
    }

    /**
     * 将 cURL 执行结果转换为 PSR-7 响应
     *
     * @param \CurlHandle $handle cURL 句柄
     * @param HeaderCollector $collector 响应头收集器
     * @param string $body 响应体
     * @return ResponseInterface PSR-7 响应
     */
    public static function buildResponse(
        \CurlHandle $handle,
        HeaderCollector $collector,
        string $body
    ): ResponseInterface {
        $status = $collector->status > 0
            ? $collector->status
            : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return MessageFactory::createResponse(
            $status > 0 ? $status : 200,
            $collector->headers,
            $body,
            $collector->version,
            $collector->reason !== '' ? $collector->reason : null
        );
    }

    /**
     * 依据 cURL 错误码构建对应异常
     *
     * @param int $errno cURL 错误码
     * @param string $error cURL 错误描述
     * @param RequestInterface $request PSR-7 请求
     * @param TransportOptions $options 传输配置
     * @return HttpException 网络异常（超时时为 TimeoutException）
     */
    public static function toException(
        int $errno,
        string $error,
        RequestInterface $request,
        TransportOptions $options
    ): HttpException {
        if (in_array($errno, self::TIMEOUT_ERRORS, true)) {
            return new TimeoutException(
                sprintf('请求超时（%.3fs）: %s', $options->timeout, $error),
                $request,
                $options->timeout
            );
        }

        return new NetworkException(
            sprintf('cURL 错误 [%d]: %s', $errno, $error),
            $request
        );
    }
}
