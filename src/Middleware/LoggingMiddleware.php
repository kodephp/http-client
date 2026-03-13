<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 日志记录中间件
 *
 * 记录请求和响应的详细信息
 * 支持自定义日志记录器
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * 日志记录器
     *
     * @var callable
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
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     */
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $startTime = microtime(true);

        $requestLog = sprintf(
            '[HTTP 请求] %s %s',
            $request->getMethod(),
            $request->getUri()
        );
        ($this->logger)($requestLog);

        try {
            $response = $next($request);

            $duration = (microtime(true) - $startTime) * 1000;
            $responseLog = sprintf(
                '[HTTP 响应] 状态码: %d %s | 耗时: %.2f ms',
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $duration
            );
            ($this->logger)($responseLog);

            return $response;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $errorLog = sprintf(
                '[HTTP 错误] %s | 耗时: %.2f ms',
                $e->getMessage(),
                $duration
            );
            ($this->logger)($errorLog);

            throw $e;
        }
    }
}
