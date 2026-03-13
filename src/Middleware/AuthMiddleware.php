<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 认证中间件
 *
 * 支持 Bearer Token 和 API Key 认证方式
 * 自动为请求添加认证头部
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Bearer Token 认证类型
     */
    public const TYPE_BEARER = 'bearer';

    /**
     * API Key 认证类型
     */
    public const TYPE_API_KEY = 'api_key';

    /**
     * 认证类型
     */
    private readonly string $type;

    /**
     * 认证凭证
     */
    private readonly string $credential;

    /**
     * API Key 的头部名称
     */
    private readonly string $apiKeyHeader;

    /**
     * 构造函数
     *
     * @param string $type 认证类型（bearer 或 api_key）
     * @param string $credential 认证凭证
     * @param string $apiKeyHeader API Key 的头部名称（仅在 API Key 认证时使用）
     */
    public function __construct(string $type, string $credential, string $apiKeyHeader = 'X-API-Key')
    {
        $this->type = $type;
        $this->credential = $credential;
        $this->apiKeyHeader = $apiKeyHeader;
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $request = match ($this->type) {
            self::TYPE_BEARER => $request->withHeader('Authorization', 'Bearer ' . $this->credential),
            self::TYPE_API_KEY => $request->withHeader($this->apiKeyHeader, $this->credential),
            default => throw new \InvalidArgumentException('不支持的认证类型: ' . $this->type),
        };

        return $next($request);
    }

    /**
     * 创建 Bearer Token 认证中间件
     *
     * @param string $token Bearer Token
     * @return self 中间件实例
     */
    public static function bearer(string $token): self
    {
        return new self(self::TYPE_BEARER, $token);
    }

    /**
     * 创建 API Key 认证中间件
     *
     * @param string $apiKey API Key
     * @param string $header API Key 的头部名称
     * @return self 中间件实例
     */
    public static function apiKey(string $apiKey, string $header = 'X-API-Key'): self
    {
        return new self(self::TYPE_API_KEY, $apiKey, $header);
    }
}
