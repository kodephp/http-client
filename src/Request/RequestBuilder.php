<?php

declare(strict_types=1);

namespace Kode\HttpClient\Request;

use Kode\HttpClient\Exception\ConfigurationException;
use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * 请求构建器
 *
 * 把「方法 + URI + 选项数组」这类人类友好的写法翻译成标准 PSR-7 请求对象，
 * 让调用方不必手工拼装 URI 查询串、编码请求体、设置 Content-Type。
 *
 * 支持的选项：
 *  - query        array|string  查询参数，会与 URI 中已有查询串合并
 *  - headers      array         请求头
 *  - json         mixed         JSON 请求体，自动设置 application/json
 *  - form         array         表单请求体，自动设置 application/x-www-form-urlencoded
 *  - body         string|StreamInterface|resource  原始请求体
 *  - version      string        HTTP 协议版本，默认 1.1
 *  - timeout      float         本次请求的超时时间（由客户端消费）
 *  - transport    array|TransportOptions  本次请求的传输层配置（由客户端消费）
 *
 * @package Kode\HttpClient\Request
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class RequestBuilder
{
    /**
     * 允许出现在选项数组中的键
     *
     * @var list<string>
     */
    public const array SUPPORTED_OPTIONS = [
        'query',
        'headers',
        'json',
        'form',
        'body',
        'version',
        'timeout',
        'transport',
    ];

    /**
     * 互斥的请求体选项
     *
     * @var list<string>
     */
    private const array BODY_OPTIONS = ['json', 'form', 'body'];

    /**
     * 私有构造函数，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 构建 PSR-7 请求对象
     *
     * @param string $method HTTP 方法
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     *
     * @throws ConfigurationException 当选项非法时抛出
     */
    public static function build(string $method, string|UriInterface $uri, array $options = []): RequestInterface
    {
        self::assertOptions($options);

        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new ConfigurationException('HTTP 方法不能为空');
        }

        $request = MessageFactory::createRequest($method, $uri);

        if (isset($options['query'])) {
            $request = $request->withUri(self::applyQuery($request->getUri(), $options['query']));
        }

        $request = self::applyBody($request, $options);

        /** @var array<string, string|array<int, string>> $headers */
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string) $name, $value);
        }

        if (isset($options['version'])) {
            $request = $request->withProtocolVersion((string) $options['version']);
        }

        return $request;
    }

    /**
     * 校验选项键名合法性
     *
     * @param array<string, mixed> $options 请求选项
     *
     * @throws ConfigurationException 当存在未知键或互斥键时抛出
     */
    public static function assertOptions(array $options): void
    {
        $unknown = array_diff(array_keys($options), self::SUPPORTED_OPTIONS);

        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                '未知的请求选项：%s，可用选项：%s',
                implode(', ', array_map(strval(...), $unknown)),
                implode(', ', self::SUPPORTED_OPTIONS)
            ));
        }

        $bodyKeys = array_values(array_filter(
            self::BODY_OPTIONS,
            static fn(string $key): bool => array_key_exists($key, $options)
        ));

        if (count($bodyKeys) > 1) {
            throw new ConfigurationException(sprintf(
                '请求体选项互斥，不能同时使用：%s',
                implode(', ', $bodyKeys)
            ));
        }
    }

    /**
     * 合并查询参数到 URI
     *
     * @param UriInterface $uri 原始 URI
     * @param mixed $query 查询参数（数组或字符串）
     *
     * @throws ConfigurationException 当查询参数类型非法时抛出
     */
    private static function applyQuery(UriInterface $uri, mixed $query): UriInterface
    {
        if (is_string($query)) {
            $appended = ltrim($query, '?&');
        } elseif (is_array($query)) {
            $appended = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } else {
            throw new ConfigurationException('query 选项只支持数组或字符串');
        }

        if ($appended === '') {
            return $uri;
        }

        $existing = $uri->getQuery();

        return $uri->withQuery($existing === '' ? $appended : $existing . '&' . $appended);
    }

    /**
     * 根据选项设置请求体与 Content-Type
     *
     * @param RequestInterface $request 请求对象
     * @param array<string, mixed> $options 请求选项
     *
     * @throws ConfigurationException 当请求体类型非法或 JSON 编码失败时抛出
     */
    private static function applyBody(RequestInterface $request, array $options): RequestInterface
    {
        if (array_key_exists('json', $options)) {
            try {
                $encoded = json_encode(
                    $options['json'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (\JsonException $e) {
                throw new ConfigurationException('json 选项编码失败：' . $e->getMessage(), 0, $e);
            }

            return $request
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody(MessageFactory::createStream($encoded));
        }

        if (array_key_exists('form', $options)) {
            if (!is_array($options['form'])) {
                throw new ConfigurationException('form 选项只支持数组');
            }

            $encoded = http_build_query($options['form'], '', '&', PHP_QUERY_RFC1738);

            return $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody(MessageFactory::createStream($encoded));
        }

        if (array_key_exists('body', $options)) {
            $body = $options['body'];

            if ($body instanceof StreamInterface) {
                return $request->withBody($body);
            }

            if (is_string($body)) {
                return $request->withBody(MessageFactory::createStream($body));
            }

            if (is_scalar($body)) {
                return $request->withBody(MessageFactory::createStream((string) $body));
            }

            throw new ConfigurationException('body 选项只支持字符串、标量或 StreamInterface');
        }

        return $request;
    }
}
