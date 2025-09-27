<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 认证中间件
 *
 * 支持 Bearer Token 和 API Key 认证方式
 */
class AuthMiddleware implements MiddlewareInterface
{
    public const TYPE_BEARER = 'bearer';
    public const TYPE_API_KEY = 'api_key';
    
    /**
     * @var string 认证类型
     */
    private string $type;
    
    /**
     * @var string 认证凭证
     */
    private string $credential;
    
    /**
     * @var string API Key 的头部名称（仅在 API Key 认证时使用）
     */
    private string $apiKeyHeader;
    
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
     * @param RequestInterface $request 请求对象
     * @param Context $context 请求上下文
     * @param callable $next 下一个中间件
     * @return ResponseInterface 响应对象
     */
    public function process(RequestInterface $request, Context $context, callable $next): ResponseInterface
    {
        // 根据认证类型添加相应的认证头部
        switch ($this->type) {
            case self::TYPE_BEARER:
                $request = $request->withHeader('Authorization', 'Bearer ' . $this->credential);
                break;
                
            case self::TYPE_API_KEY:
                $request = $request->withHeader($this->apiKeyHeader, $this->credential);
                break;
                
            default:
                throw new \InvalidArgumentException('Unsupported authentication type: ' . $this->type);
        }
        
        // 执行下一个中间件
        return $next($request, $context);
    }
    
    /**
     * 创建 Bearer Token 认证中间件
     *
     * @param string $token Bearer Token
     * @return self
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
     * @return self
     */
    public static function apiKey(string $apiKey, string $header = 'X-API-Key'): self
    {
        return new self(self::TYPE_API_KEY, $apiKey, $header);
    }
}