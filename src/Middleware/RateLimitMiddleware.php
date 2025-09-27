<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 限流中间件
 *
 * 实现请求频率限制，使用令牌桶算法
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @var int 桶的容量
     */
    private int $capacity;
    
    /**
     * @var int 令牌生成速率（每秒生成的令牌数）
     */
    private int $rate;
    
    /**
     * @var float 当前令牌数
     */
    private float $tokens;
    
    /**
     * @var float 上次更新时间
     */
    private float $lastUpdate;
    
    /**
     * @var array<string, mixed> 限流配置
     */
    private array $config;
    
    /**
     * 构造函数
     *
     * @param int $capacity 桶的容量
     * @param int $rate 令牌生成速率（每秒生成的令牌数）
     * @param array<string, mixed> $config 限流配置
     */
    public function __construct(int $capacity = 10, int $rate = 1, array $config = [])
    {
        $this->capacity = $capacity;
        $this->rate = $rate;
        $this->tokens = $capacity;
        $this->lastUpdate = microtime(true);
        $this->config = $config;
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
        // 更新令牌数
        $this->updateTokens();
        
        // 检查是否有足够的令牌
        if ($this->tokens < 1) {
            // 计算需要等待的时间
            $waitTime = (1 - $this->tokens) / $this->rate;
            
            // 如果配置了阻塞等待，则等待
            if (!empty($this->config['blocking'])) {
                usleep((int)($waitTime * 1000000));
                $this->updateTokens();
            } else {
                // 否则抛出异常
                throw new \RuntimeException('Rate limit exceeded. Please try again later.');
            }
        }
        
        // 消耗一个令牌
        $this->tokens--;
        
        // 执行下一个中间件
        return $next($request, $context);
    }
    
    /**
     * 更新令牌数
     *
     * @return void
     */
    private function updateTokens(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastUpdate;
        $this->lastUpdate = $now;
        
        // 根据时间计算新生成的令牌数
        $newTokens = $elapsed * $this->rate;
        $this->tokens = min($this->capacity, $this->tokens + $newTokens);
    }
}