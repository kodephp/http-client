<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 日志记录中间件
 *
 * 记录请求和响应的详细信息
 */
class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * @var callable 日志记录器
     */
    private $logger;

    /**
     * 构造函数
     *
     * @param callable $logger 日志记录器函数，接受一个字符串参数
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
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
        // 记录请求开始时间
        $startTime = microtime(true);
        
        // 记录请求信息
        $requestLog = sprintf(
            'HTTP Request: %s %s',
            $request->getMethod(),
            $request->getUri()
        );
        
        ($this->logger)($requestLog);

        try {
            // 执行下一个中间件
            $response = $next($request, $context);
            
            // 记录响应信息
            $duration = (microtime(true) - $startTime) * 1000;
            $responseLog = sprintf(
                'HTTP Response: %d %s (%.2f ms)',
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $duration
            );
            
            ($this->logger)($responseLog);
            
            return $response;
        } catch (\Exception $e) {
            // 记录异常信息
            $duration = (microtime(true) - $startTime) * 1000;
            $errorLog = sprintf(
                'HTTP Error: %s (%.2f ms)',
                $e->getMessage(),
                $duration
            );
            
            ($this->logger)($errorLog);
            
            throw $e;
        }
    }
}