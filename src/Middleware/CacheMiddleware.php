<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 缓存中间件
 *
 * 实现响应缓存功能
 */
class CacheMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, array{response: ResponseInterface, expires: int}> 缓存存储
     */
    private array $cache = [];
    
    /**
     * @var int 默认缓存时间（秒）
     */
    private int $defaultTtl;
    
    /**
     * 构造函数
     *
     * @param int $defaultTtl 默认缓存时间（秒）
     */
    public function __construct(int $defaultTtl = 300)
    {
        $this->defaultTtl = $defaultTtl;
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
        $cacheKey = $this->generateCacheKey($request);
        
        // 检查缓存是否存在且未过期
        if (isset($this->cache[$cacheKey])) {
            $cacheEntry = $this->cache[$cacheKey];
            
            if (time() < $cacheEntry['expires']) {
                // 返回缓存的响应
                return $cacheEntry['response'];
            } else {
                // 缓存已过期，删除缓存
                unset($this->cache[$cacheKey]);
            }
        }
        
        // 执行下一个中间件获取响应
        $response = $next($request, $context);
        
        // 获取缓存时间（简化实现，实际项目中可能需要通过上下文传递）
        $ttl = $this->defaultTtl;
        $expires = time() + $ttl;
        
        // 缓存响应
        $this->cache[$cacheKey] = [
            'response' => $response,
            'expires' => $expires
        ];
        
        return $response;
    }
    
    /**
     * 生成缓存键
     *
     * @param RequestInterface $request 请求对象
     * @return string 缓存键
     */
    private function generateCacheKey(RequestInterface $request): string
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        
        return md5($method . $uri . $body);
    }
    
    /**
     * 清除缓存
     *
     * @param string|null $cacheKey 缓存键，如果为 null 则清除所有缓存
     * @return void
     */
    public function clearCache(?string $cacheKey = null): void
    {
        if ($cacheKey === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$cacheKey]);
        }
    }
    
    /**
     * 获取缓存统计信息
     *
     * @return array<string, int> 缓存统计信息
     */
    public function getCacheStats(): array
    {
        $total = count($this->cache);
        $valid = 0;
        $expired = 0;
        $now = time();
        
        foreach ($this->cache as $entry) {
            if ($now < $entry['expires']) {
                $valid++;
            } else {
                $expired++;
            }
        }
        
        return [
            'total' => $total,
            'valid' => $valid,
            'expired' => $expired
        ];
    }
}