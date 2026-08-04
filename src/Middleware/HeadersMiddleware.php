<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 默认请求头中间件
 *
 * v2.4 新增。为所有出站请求统一补充默认头（如 Accept、X-Request-Id、租户标识等）。
 * 默认仅在请求未显式设置该头时才补充，也可切换为强制覆盖模式。
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class HeadersMiddleware implements MiddlewareInterface
{
    /**
     * 构造函数
     *
     * @param array<string, string|array<int, string>> $headers 需要补充的请求头
     * @param bool $override true 表示强制覆盖已有同名头
     */
    public function __construct(
        private readonly array $headers,
        private readonly bool $override = false,
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
        foreach ($this->headers as $name => $value) {
            if (!$this->override && $request->hasHeader($name)) {
                continue;
            }

            $request = $request->withHeader($name, $value);
        }

        return $next($request);
    }
}
