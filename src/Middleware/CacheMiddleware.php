<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 缓存中间件
 *
 * v2.4 重写要点：
 *  - 安全修复：旧版缓存键只由「方法 + URI」构成，携带不同 Authorization / Cookie
 *    的请求会命中同一份缓存，存在跨用户数据泄露风险。新版将 Vary 头纳入缓存键。
 *  - 内存修复：旧版缓存数组无上限，长驻进程（Swoole / CLI 常驻）下会持续膨胀。
 *    新版采用 LRU 淘汰并可配置容量上限。
 *  - 语义增强：遵循请求与响应的 Cache-Control（no-store / no-cache / private / max-age），
 *    支持 HEAD 请求缓存，命中时补充 X-Kode-Cache: HIT 标识。
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class CacheMiddleware implements MiddlewareInterface
{
    /**
     * 可缓存的请求方法
     *
     * @var list<string>
     */
    private const array CACHEABLE_METHODS = ['GET', 'HEAD'];

    /**
     * 默认参与缓存键计算的请求头
     *
     * @var list<string>
     */
    public const array DEFAULT_VARY_HEADERS = ['Authorization', 'Cookie', 'Accept', 'Accept-Language'];

    /**
     * 缓存命中标识响应头
     */
    public const string CACHE_HEADER = 'X-Kode-Cache';

    /**
     * 缓存存储（按最近使用顺序排列，队首为最久未使用）
     *
     * @var array<string, array{status: int, headers: array<string, array<int, string>>, body: string, version: string, reason: string, expires: float}>
     */
    private array $cache = [];

    /**
     * 命中次数
     */
    private int $hits = 0;

    /**
     * 未命中次数
     */
    private int $misses = 0;

    /**
     * 构造函数
     *
     * @param int $defaultTtl 默认缓存时间（秒）
     * @param int $maxEntries 最大缓存条目数，超出后按 LRU 淘汰
     * @param list<string> $varyHeaders 参与缓存键计算的请求头
     * @param bool $respectCacheControl 是否遵循 Cache-Control 指令
     */
    public function __construct(
        private readonly int $defaultTtl = 300,
        private readonly int $maxEntries = 256,
        private readonly array $varyHeaders = self::DEFAULT_VARY_HEADERS,
        private readonly bool $respectCacheControl = true,
    ) {
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
        if (!in_array(strtoupper($request->getMethod()), self::CACHEABLE_METHODS, true)) {
            return $next($request);
        }

        $requestDirectives = $this->parseCacheControl($request->getHeaderLine('Cache-Control'));

        if ($this->respectCacheControl && isset($requestDirectives['no-store'])) {
            return $next($request);
        }

        $key = $this->generateCacheKey($request);
        $allowCacheRead = !($this->respectCacheControl && isset($requestDirectives['no-cache']));

        if ($allowCacheRead && isset($this->cache[$key])) {
            $entry = $this->cache[$key];

            if (microtime(true) < $entry['expires']) {
                // LRU：命中后移动到队尾
                unset($this->cache[$key]);
                $this->cache[$key] = $entry;
                $this->hits++;

                return $this->buildResponse($entry, true);
            }

            unset($this->cache[$key]);
        }

        $this->misses++;
        $response = $next($request);

        return $this->store($key, $response);
    }

    /**
     * 尝试写入缓存并返回可重复读取的响应
     *
     * @param string $key 缓存键
     * @param ResponseInterface $response 上游响应
     * @return ResponseInterface 可安全重复读取的响应
     */
    private function store(string $key, ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return $response;
        }

        $directives = $this->parseCacheControl($response->getHeaderLine('Cache-Control'));

        if ($this->respectCacheControl
            && (isset($directives['no-store']) || isset($directives['private']))
        ) {
            return $response;
        }

        $ttl = $this->defaultTtl;
        if ($this->respectCacheControl && isset($directives['max-age']) && is_numeric($directives['max-age'])) {
            $ttl = (int) $directives['max-age'];
        }

        $body = (string) $response->getBody();

        if ($ttl <= 0) {
            return $this->rebuildResponse($response, $body);
        }

        $entry = [
            'status' => $status,
            'headers' => $response->getHeaders(),
            'body' => $body,
            'version' => $response->getProtocolVersion(),
            'reason' => $response->getReasonPhrase(),
            'expires' => microtime(true) + $ttl,
        ];

        unset($this->cache[$key]);
        $this->cache[$key] = $entry;

        $this->evictIfNeeded();

        return $this->buildResponse($entry, false);
    }

    /**
     * 按 LRU 淘汰超出容量的条目
     */
    private function evictIfNeeded(): void
    {
        if ($this->maxEntries <= 0) {
            return;
        }

        while (count($this->cache) > $this->maxEntries) {
            $oldest = array_key_first($this->cache);
            if ($oldest === null) {
                break;
            }
            unset($this->cache[$oldest]);
        }
    }

    /**
     * 由缓存条目重建响应
     *
     * @param array{status: int, headers: array<string, array<int, string>>, body: string, version: string, reason: string, expires: float} $entry 缓存条目
     * @param bool $fromCache 是否为缓存命中
     * @return ResponseInterface PSR-7 响应对象
     */
    private function buildResponse(array $entry, bool $fromCache): ResponseInterface
    {
        $headers = $entry['headers'];
        $headers[self::CACHE_HEADER] = [$fromCache ? 'HIT' : 'MISS'];

        return MessageFactory::createResponse(
            $entry['status'],
            $headers,
            $entry['body'],
            $entry['version'],
            $entry['reason'] !== '' ? $entry['reason'] : null
        );
    }

    /**
     * 重建一份响应体可重复读取的响应
     *
     * @param ResponseInterface $response 原响应
     * @param string $body 已读取的响应体
     * @return ResponseInterface 新响应
     */
    private function rebuildResponse(ResponseInterface $response, string $body): ResponseInterface
    {
        return $response->withBody(MessageFactory::createStream($body));
    }

    /**
     * 生成缓存键
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return string 缓存键
     */
    private function generateCacheKey(RequestInterface $request): string
    {
        $parts = [
            strtoupper($request->getMethod()),
            (string) $request->getUri(),
        ];

        foreach ($this->varyHeaders as $header) {
            $value = $request->getHeaderLine($header);
            if ($value !== '') {
                $parts[] = strtolower($header) . '=' . $value;
            }
        }

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * 解析 Cache-Control 指令
     *
     * @param string $header Cache-Control 头内容
     * @return array<string, string|true> 指令表
     */
    private function parseCacheControl(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $directives = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $position = strpos($part, '=');
            if ($position === false) {
                $directives[strtolower($part)] = true;
                continue;
            }

            $name = strtolower(trim(substr($part, 0, $position)));
            $directives[$name] = trim(substr($part, $position + 1), " \t\"");
        }

        return $directives;
    }

    /**
     * 清除缓存
     *
     * @param string|null $cacheKey 缓存键，为 null 时清除全部
     */
    public function clearCache(?string $cacheKey = null): void
    {
        if ($cacheKey === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$cacheKey]);
    }

    /**
     * 获取缓存统计信息
     *
     * @return array{total: int, valid: int, expired: int, hits: int, misses: int, capacity: int} 统计信息
     */
    public function getCacheStats(): array
    {
        $valid = 0;
        $expired = 0;
        $now = microtime(true);

        foreach ($this->cache as $entry) {
            if ($now < $entry['expires']) {
                $valid++;
            } else {
                $expired++;
            }
        }

        return [
            'total' => count($this->cache),
            'valid' => $valid,
            'expired' => $expired,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'capacity' => $this->maxEntries,
        ];
    }
}
