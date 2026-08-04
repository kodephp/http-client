<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Exception\ConfigurationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 日志中间件
 *
 * 记录请求、响应与异常的关键信息。
 *
 * v2.4 增强：
 *  - 同时支持 callable 与 PSR-3 LoggerInterface（无需硬依赖 psr/log）
 *  - 日志中自动带上上下文中的请求 ID，便于全链路追踪
 *  - 可选记录请求/响应体摘要（自动截断，避免日志爆炸）
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * 归一化后的日志写入函数
     *
     * @var \Closure(string, string): void
     */
    private readonly \Closure $writer;

    /**
     * 构造函数
     *
     * @param callable|object $logger 日志记录器：callable(string $message) 或 PSR-3 LoggerInterface
     * @param string $level PSR-3 日志级别（仅在传入 LoggerInterface 时生效）
     * @param bool $logBody 是否记录请求/响应体摘要
     * @param int $bodyLimit 体摘要的最大字符数
     */
    public function __construct(
        callable|object $logger,
        private readonly string $level = 'info',
        private readonly bool $logBody = false,
        private readonly int $bodyLimit = 512,
    ) {
        $this->writer = $this->normalizeLogger($logger);
    }

    /**
     * 归一化日志记录器
     *
     * @param callable|object $logger 日志记录器
     * @return \Closure(string, string): void 归一化后的写入函数
     */
    private function normalizeLogger(callable|object $logger): \Closure
    {
        if (is_object($logger)
            && !$logger instanceof \Closure
            && method_exists($logger, 'log')
            && interface_exists('Psr\\Log\\LoggerInterface')
            && $logger instanceof \Psr\Log\LoggerInterface
        ) {
            return static function (string $message, string $level) use ($logger): void {
                $logger->log($level, $message);
            };
        }

        if (is_callable($logger)) {
            $callable = \Closure::fromCallable($logger);

            return static function (string $message, string $level) use ($callable): void {
                $callable($message);
            };
        }

        throw new ConfigurationException('logger 必须是 callable 或 PSR-3 LoggerInterface 实例');
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
        $startTime = microtime(true);
        $traceId = Context::getRequestId();
        $prefix = $traceId !== null ? sprintf('[%s] ', substr($traceId, 0, 8)) : '';

        $message = sprintf(
            '%s[HTTP 请求] %s %s',
            $prefix,
            strtoupper($request->getMethod()),
            $request->getUri()
        );

        if ($this->logBody) {
            $message .= ' | 请求体: ' . $this->summarize((string) $request->getBody());
        }

        ($this->writer)($message, $this->level);

        try {
            $response = $next($request);

            $duration = (microtime(true) - $startTime) * 1000;
            $message = sprintf(
                '%s[HTTP 响应] 状态码: %d %s | 耗时: %.2f ms',
                $prefix,
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $duration
            );

            if ($this->logBody) {
                $message .= ' | 响应体: ' . $this->summarize((string) $response->getBody());
            }

            ($this->writer)($message, $this->level);

            return $response;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            ($this->writer)(
                sprintf(
                    '%s[HTTP 错误] %s: %s | 耗时: %.2f ms',
                    $prefix,
                    $e::class,
                    $e->getMessage(),
                    $duration
                ),
                'error'
            );

            throw $e;
        }
    }

    /**
     * 截断过长的消息体
     *
     * @param string $body 原始内容
     * @return string 摘要
     */
    private function summarize(string $body): string
    {
        if ($body === '') {
            return '(空)';
        }

        if (mb_strlen($body) <= $this->bodyLimit) {
            return $body;
        }

        return mb_substr($body, 0, $this->bodyLimit) . sprintf('...(共 %d 字符)', mb_strlen($body));
    }
}
