<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Exception\ConfigurationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 认证中间件
 *
 * 自动为出站请求附加认证信息。
 *
 * v2.4 增强：新增 Basic 认证与动态令牌（callable）支持，
 * 便于对接会过期、需要刷新的 Token 场景。
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Bearer Token 认证
     */
    public const string TYPE_BEARER = 'bearer';

    /**
     * API Key 认证
     */
    public const string TYPE_API_KEY = 'api_key';

    /**
     * HTTP Basic 认证
     */
    public const string TYPE_BASIC = 'basic';

    /**
     * 认证凭证（字符串或返回字符串的可调用对象）
     *
     * @var string|\Closure(): string
     */
    private readonly string|\Closure $credential;

    /**
     * 构造函数
     *
     * @param string $type 认证类型（bearer / api_key / basic）
     * @param string|callable $credential 认证凭证，或返回凭证的回调（支持动态刷新）
     * @param string $apiKeyHeader API Key 的头名称（仅 api_key 类型生效）
     */
    public function __construct(
        private readonly string $type,
        string|callable $credential,
        private readonly string $apiKeyHeader = 'X-API-Key',
    ) {
        if (!in_array($type, [self::TYPE_BEARER, self::TYPE_API_KEY, self::TYPE_BASIC], true)) {
            throw new ConfigurationException('不支持的认证类型: ' . $type);
        }

        $this->credential = is_string($credential)
            ? $credential
            : \Closure::fromCallable($credential);
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $credential = $this->resolveCredential();

        $request = match ($this->type) {
            self::TYPE_BEARER => $request->withHeader('Authorization', 'Bearer ' . $credential),
            self::TYPE_API_KEY => $request->withHeader($this->apiKeyHeader, $credential),
            self::TYPE_BASIC => $request->withHeader('Authorization', 'Basic ' . $credential),
        };

        return $next($request);
    }

    /**
     * 解析当前凭证
     */
    private function resolveCredential(): string
    {
        return $this->credential instanceof \Closure
            ? (string) ($this->credential)()
            : $this->credential;
    }

    /**
     * 创建 Bearer Token 认证中间件
     *
     * @param string|callable $token Bearer Token 或返回 Token 的回调
     */
    public static function bearer(string|callable $token): self
    {
        return new self(self::TYPE_BEARER, $token);
    }

    /**
     * 创建 API Key 认证中间件
     *
     * @param string|callable $apiKey API Key 或返回 Key 的回调
     * @param string $header API Key 的头名称
     */
    public static function apiKey(string|callable $apiKey, string $header = 'X-API-Key'): self
    {
        return new self(self::TYPE_API_KEY, $apiKey, $header);
    }

    /**
     * 创建 HTTP Basic 认证中间件
     *
     * @param string $username 用户名
     * @param string $password 密码
     */
    public static function basic(string $username, string $password): self
    {
        return new self(self::TYPE_BASIC, base64_encode($username . ':' . $password));
    }
}
