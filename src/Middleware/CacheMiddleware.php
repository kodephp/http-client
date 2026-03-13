<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * 缓存中间件
 *
 * 实现响应缓存功能，减少重复请求
 * 支持自定义缓存时间和缓存键生成
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class CacheMiddleware implements MiddlewareInterface
{
    /**
     * 缓存存储
     *
     * @var array<string, array{status: int, headers: array<string, array<int, string>>, body: string, expires: int}>
     */
    private array $cache = [];

    /**
     * 默认缓存时间（秒）
     */
    private readonly int $defaultTtl;

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
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        if ($request->getMethod() !== 'GET') {
            return $next($request);
        }

        $cacheKey = $this->generateCacheKey($request);

        if (isset($this->cache[$cacheKey])) {
            $cacheEntry = $this->cache[$cacheKey];

            if (time() < $cacheEntry['expires']) {
                return new Response(
                    $cacheEntry['status'],
                    $cacheEntry['headers'],
                    $cacheEntry['body']
                );
            }

            unset($this->cache[$cacheKey]);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = (string) $response->getBody();

            $this->cache[$cacheKey] = [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $body,
                'expires' => time() + $this->defaultTtl,
            ];

            return new Response(
                $response->getStatusCode(),
                $response->getHeaders(),
                $body
            );
        }

        return $response;
    }

    /**
     * 生成缓存键
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return string 缓存键
     */
    private function generateCacheKey(RequestInterface $request): string
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        return md5($method . ':' . $uri);
    }

    /**
     * 清除缓存
     *
     * @param string|null $cacheKey 缓存键，如果为 null 则清除所有缓存
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
     * @return array{total: int, valid: int, expired: int} 缓存统计信息
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
            'expired' => $expired,
        ];
    }
}
