<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\Context\Context as BaseContext;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 分布式上下文传播中间件（链路追踪）
 *
 * 借助 kode/context 3.x 的 toHeaders()/fromHeaders()，把当前请求的链路上下文
 * （W3C traceparent/tracestate 以及 X-Context-* 私有头）自动注入到出站请求，
 * 从而打通跨服务的全链路追踪。
 *
 * 依赖 kode/context ^3.1 及以上。若当前没有活跃链路（尚未调用 startTrace()），
 * 仍会注入已存在的 X-Context-* 头（如请求 ID、关联 ID），但不会注入 W3C 头。
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class TracingMiddleware implements MiddlewareInterface
{
    /**
     * @param bool $propagateResponse 收到响应后，是否把下游返回的 X-Context-* 上下文回写到当前上下文
     */
    public function __construct(
        private readonly bool $propagateResponse = false,
    ) {
    }

    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        foreach (BaseContext::toHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $next($request);

        if ($this->propagateResponse) {
            $flat = [];
            foreach ($response->getHeaders() as $name => $values) {
                $flat[$name] = \is_array($values) ? implode(', ', $values) : $values;
            }
            BaseContext::fromHeaders($flat);
        }

        return $response;
    }
}
