<?php

declare(strict_types=1);

namespace Kode\HttpClient;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;
use Kode\HttpClient\Driver\ConcurrentDriverInterface;
use Kode\HttpClient\Driver\DriverInterface;
use Kode\HttpClient\Exception\HttpException;
use Kode\HttpClient\Message\MessageFactory;
use Kode\HttpClient\Middleware\MiddlewareInterface;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Request\RequestBuilder;
use Kode\HttpClient\Response\HttpResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP 客户端实现
 *
 * 支持多种运行时环境（FPM、CLI、Swoole、Swow、Fiber、Amp），
 * 在 PSR-18 之上提供语义化方法、基础 URI、批量并发与中间件机制。
 *
 * @package Kode\HttpClient
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class HttpClient implements HttpClientInterface
{
    /**
     * 构造函数
     *
     * @param DriverInterface $driver HTTP 驱动实例
     * @param MiddlewareStack|null $middlewareStack 中间件栈（可选）
     * @param string|null $baseUri 基础 URI，相对路径会基于它解析
     */
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly ?MiddlewareStack $middlewareStack = null,
        private readonly ?string $baseUri = null,
    ) {
    }

    /**
     * 发送 HTTP 请求（PSR-18 标准入口）
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return HttpResponse 带便捷方法的响应对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): HttpResponse
    {
        return HttpResponse::wrap($this->dispatch($request));
    }

    /**
     * 以语义化方式发送请求
     *
     * @param string $method HTTP 方法
     * @param string|UriInterface $uri 请求 URI（支持相对于 baseUri 的相对路径）
     * @param array<string, mixed> $options 请求选项
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    #[\Override]
    public function request(string $method, string|UriInterface $uri, array $options = []): HttpResponse
    {
        RequestBuilder::assertOptions($options);

        $request = RequestBuilder::build($method, $this->resolveUri($uri), $options);
        $scoped = $this->extractScopedOptions($options);

        if ($scoped === null) {
            return $this->sendRequest($request);
        }

        return $this->withScopedContext($scoped, fn(): HttpResponse => $this->sendRequest($request));
    }

    /**
     * 发送 GET 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function get(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * 发送 POST 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function post(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * 发送 PUT 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function put(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * 发送 PATCH 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function patch(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * 发送 DELETE 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function delete(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * 发送 HEAD 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function head(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * 发送 OPTIONS 请求
     *
     * @param string|UriInterface $uri 请求 URI
     * @param array<string, mixed> $options 请求选项
     */
    #[\Override]
    public function options(string|UriInterface $uri, array $options = []): HttpResponse
    {
        return $this->request('OPTIONS', $uri, $options);
    }

    /**
     * 批量发送请求
     *
     * 当驱动支持并发（{@see ConcurrentDriverInterface}）且未配置中间件时走真正的并行链路；
     * 否则退化为顺序执行，但依然保持「全部落定」语义。
     *
     * @param array<array-key, RequestInterface> $requests 请求集合
     * @return array<array-key, HttpResponse|\Throwable> 结果集合，键与入参一致
     */
    #[\Override]
    public function sendConcurrent(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        if ($this->supportsParallel()) {
            /** @var ConcurrentDriverInterface $driver */
            $driver = $this->driver;

            return array_map(
                static fn(mixed $result): mixed => $result instanceof ResponseInterface
                    ? HttpResponse::wrap($result)
                    : $result,
                $driver->sendConcurrent($requests)
            );
        }

        $results = [];

        foreach ($requests as $key => $request) {
            if (!$request instanceof RequestInterface) {
                $results[$key] = new \InvalidArgumentException('批量请求集合中存在非 PSR-7 请求对象');
                continue;
            }

            try {
                $results[$key] = $this->sendRequest($request);
            } catch (\Throwable $e) {
                $results[$key] = $e;
            }
        }

        return $results;
    }

    /**
     * 批量发送语义化请求
     *
     * @param array<array-key, array{0: string, 1: string|UriInterface, 2?: array<string, mixed>}> $specs
     *        请求描述集合，每项为 [method, uri, options]
     * @return array<array-key, HttpResponse|\Throwable> 结果集合，键与入参一致
     */
    public function pool(array $specs): array
    {
        $requests = [];
        $failures = [];

        foreach ($specs as $key => $spec) {
            try {
                $method = (string) ($spec[0] ?? 'GET');
                /** @var string|UriInterface $uri */
                $uri = $spec[1] ?? '';
                /** @var array<string, mixed> $options */
                $options = $spec[2] ?? [];

                $requests[$key] = RequestBuilder::build($method, $this->resolveUri($uri), $options);
            } catch (\Throwable $e) {
                $failures[$key] = $e;
            }
        }

        $results = $requests === [] ? [] : $this->sendConcurrent($requests);

        $ordered = [];
        foreach ($specs as $key => $_) {
            $ordered[$key] = $failures[$key] ?? $results[$key] ?? new \RuntimeException('请求未产生结果');
        }

        return $ordered;
    }

    /**
     * 当前客户端是否具备真正的并发能力
     */
    public function supportsParallel(): bool
    {
        return $this->driver instanceof ConcurrentDriverInterface
            && ($this->middlewareStack === null || $this->middlewareStack->isEmpty());
    }

    /**
     * 获取驱动实例
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * 获取中间件栈
     */
    public function getMiddlewareStack(): ?MiddlewareStack
    {
        return $this->middlewareStack;
    }

    /**
     * 获取基础 URI
     */
    public function getBaseUri(): ?string
    {
        return $this->baseUri;
    }

    /**
     * 派生一个替换了中间件栈的新客户端
     *
     * @param MiddlewareStack $middlewareStack 中间件栈
     */
    public function withMiddlewareStack(MiddlewareStack $middlewareStack): static
    {
        return new static($this->driver, $middlewareStack, $this->baseUri);
    }

    /**
     * 派生一个追加了中间件的新客户端
     *
     * @param MiddlewareInterface ...$middleware 待追加的中间件
     */
    public function withMiddleware(MiddlewareInterface ...$middleware): static
    {
        $stack = $this->middlewareStack?->with(...$middleware) ?? (new MiddlewareStack())->addMany($middleware);

        return new static($this->driver, $stack, $this->baseUri);
    }

    /**
     * 派生一个替换了驱动的新客户端
     *
     * @param DriverInterface $driver HTTP 驱动实例
     */
    public function withDriver(DriverInterface $driver): static
    {
        return new static($driver, $this->middlewareStack, $this->baseUri);
    }

    /**
     * 派生一个替换了基础 URI 的新客户端
     *
     * @param string|null $baseUri 基础 URI
     */
    public function withBaseUri(?string $baseUri): static
    {
        return new static($this->driver, $this->middlewareStack, $baseUri);
    }

    /**
     * 发送带上下文的 HTTP 请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param mixed $context 请求上下文（已废弃，保留向后兼容）
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     * @deprecated 2.4.0 使用 Context::setTimeout() 等静态方法或 request() 的 timeout 选项代替
     */
    public function sendRequestWithContext(RequestInterface $request, mixed $context = null): HttpResponse
    {
        return $this->sendRequest($request);
    }

    /**
     * 走完中间件栈并交由驱动执行
     *
     * @param RequestInterface $request PSR-7 请求对象
     *
     * @throws HttpException 当发生网络错误或协议错误时抛出
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $handler = fn(RequestInterface $req): ResponseInterface => $this->driver->sendRequest($req);

        if ($this->middlewareStack === null || $this->middlewareStack->isEmpty()) {
            return $handler($request);
        }

        return $this->middlewareStack->handle($request, $handler);
    }

    /**
     * 解析请求 URI
     *
     * 绝对 URI 原样返回；相对路径在配置了 baseUri 时基于其拼接。
     *
     * @param string|UriInterface $uri 请求 URI
     */
    private function resolveUri(string|UriInterface $uri): string|UriInterface
    {
        if ($this->baseUri === null || $this->baseUri === '') {
            return $uri;
        }

        $target = $uri instanceof UriInterface ? (string) $uri : $uri;

        if ($target !== '' && preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target) === 1) {
            return $uri;
        }

        if ($target === '') {
            return MessageFactory::createUri($this->baseUri);
        }

        return MessageFactory::createUri(
            rtrim($this->baseUri, '/') . '/' . ltrim($target, '/')
        );
    }

    /**
     * 提取仅对本次请求生效的上下文级选项
     *
     * @param array<string, mixed> $options 请求选项
     * @return array{timeout: float|null, transport: TransportOptions|null}|null 无此类选项时返回 null
     */
    private function extractScopedOptions(array $options): ?array
    {
        $hasTimeout = array_key_exists('timeout', $options);
        $hasTransport = array_key_exists('transport', $options);

        if (!$hasTimeout && !$hasTransport) {
            return null;
        }

        $transport = null;
        if ($hasTransport) {
            $raw = $options['transport'];
            $transport = $raw instanceof TransportOptions
                ? $raw
                : TransportOptions::fromArray((array) $raw);
        }

        $timeout = $hasTimeout ? (float) $options['timeout'] : null;

        if ($timeout !== null && $transport !== null) {
            $transport = $transport->with(['timeout' => $timeout]);
        }

        return ['timeout' => $timeout, 'transport' => $transport];
    }

    /**
     * 在临时上下文中执行回调，执行完成后恢复原有上下文
     *
     * @template T
     * @param array{timeout: float|null, transport: TransportOptions|null} $scoped 临时选项
     * @param callable(): T $callback 待执行回调
     * @return T 回调返回值
     */
    private function withScopedContext(array $scoped, callable $callback): mixed
    {
        $previousTimeout = Context::getTimeout();
        $previousTransport = Context::rawTransportOptions();

        if ($scoped['timeout'] !== null) {
            Context::setTimeout($scoped['timeout']);
        }

        if ($scoped['transport'] !== null) {
            Context::setTransportOptions($scoped['transport']);
        } elseif ($scoped['timeout'] !== null && $previousTransport !== null) {
            Context::setTransportOptions($previousTransport->with(['timeout' => $scoped['timeout']]));
        }

        try {
            return $callback();
        } finally {
            Context::setTransportOptions($previousTransport);

            if ($previousTimeout !== null) {
                Context::setTimeout($previousTimeout);
            } else {
                Context::clearTimeout();
            }
        }
    }
}
