# API 文档

适用版本：**2.5.0**（PHP >= 8.3）

## 目录

- [HttpClient](#httpclient)
- [请求选项](#请求选项)
- [HttpResponse](#httpresponse)
- [Factory](#factory)
- [TransportOptions](#transportoptions)
- [Context](#context)
- [中间件](#中间件)
- [驱动](#驱动)
- [异常](#异常)

---

## HttpClient

`Kode\HttpClient\HttpClient`，实现 `Kode\HttpClient\HttpClientInterface`（继承 PSR-18 `Psr\Http\Client\ClientInterface`）。

实例是**不可变**的：`withXxx()` 系列方法返回新实例，不修改原对象。

### 构造函数

```php
public function __construct(
    DriverInterface $driver,
    ?MiddlewareStack $middlewareStack = null,
    ?string $baseUri = null,
)
```

| 参数 | 说明 |
|------|------|
| `$driver` | 传输驱动，**必填** |
| `$middlewareStack` | 中间件栈，为 `null` 时直连驱动 |
| `$baseUri` | 基础 URI，相对路径会基于它解析 |

> 通常不直接 `new`，推荐使用 [`Factory::create()`](#factory)。

### sendRequest

发送 PSR-7 请求（PSR-18 标准方法）。

```php
public function sendRequest(RequestInterface $request): HttpResponse
```

返回 [`HttpResponse`](#httpresponse)（PSR-7 `ResponseInterface` 的协变子类型）。

### request

按方法与选项构建并发送请求。

```php
public function request(string $method, string|UriInterface $uri, array $options = []): HttpResponse
```

| 参数 | 说明 |
|------|------|
| `$method` | HTTP 方法，如 `GET` |
| `$uri` | 绝对 URI，或相对于 `baseUri` 的路径 |
| `$options` | 见[请求选项](#请求选项) |

**抛出：** `ConfigurationException`（选项非法）、`NetworkException` / `TimeoutException`（传输失败）。

### 快捷方法

```php
public function get(string|UriInterface $uri, array $options = []): HttpResponse
public function post(string|UriInterface $uri, array $options = []): HttpResponse
public function put(string|UriInterface $uri, array $options = []): HttpResponse
public function patch(string|UriInterface $uri, array $options = []): HttpResponse
public function delete(string|UriInterface $uri, array $options = []): HttpResponse
public function head(string|UriInterface $uri, array $options = []): HttpResponse
public function options(string|UriInterface $uri, array $options = []): HttpResponse
```

### sendConcurrent

批量发送已构建的 PSR-7 请求，采用**全部落定**语义：单个请求失败不会中断其余请求。

```php
public function sendConcurrent(array $requests): array
```

| 项 | 说明 |
|------|------|
| 入参 | `array<array-key, RequestInterface>` |
| 返回 | `array<array-key, HttpResponse\|\Throwable>`，**键与入参一一对应** |

当驱动实现了 `ConcurrentDriverInterface` **且**未启用任何中间件时，走真正的并行路径（`curl_multi` 或协程）；否则退化为顺序执行。可用 [`supportsParallel()`](#supportsparallel) 判断。

### pool

与 `sendConcurrent()` 相同的语义，但接受待构建的请求描述。

```php
public function pool(array $specs): array
```

每项格式为 `[string $method, string|UriInterface $uri, array $options = []]`。构建阶段失败的项会以 `Throwable` 形式直接放入结果对应位置。

### supportsParallel

```php
public function supportsParallel(): bool
```

返回当前客户端能否真正并行（驱动支持并发 **且** 中间件栈为空）。

### 访问器与派生方法

```php
public function getDriver(): DriverInterface
public function getMiddlewareStack(): ?MiddlewareStack
public function getBaseUri(): ?string

public function withDriver(DriverInterface $driver): static
public function withMiddlewareStack(MiddlewareStack $middlewareStack): static
public function withMiddleware(MiddlewareInterface ...$middleware): static
public function withBaseUri(?string $baseUri): static
```

### sendRequestWithContext

```php
@deprecated 2.4.0
public function sendRequestWithContext(RequestInterface $request, mixed $context = null): HttpResponse
```

已废弃。请改用 `Context::setTimeout()` 等静态方法，或 `request()` 的 `timeout` 选项。

---

## 请求选项

`request()` 及所有快捷方法接受的选项键（`Kode\HttpClient\Request\RequestBuilder::SUPPORTED_OPTIONS`）。传入未知键会抛出 `ConfigurationException`。

| 选项 | 类型 | 说明 |
|------|------|------|
| `query` | `array\|string` | 查询参数，自动 RFC3986 编码并合并到既有查询串 |
| `headers` | `array<string, string\|string[]>` | 请求头 |
| `json` | `mixed` | JSON 请求体，自动设 `Content-Type: application/json; charset=utf-8` |
| `form` | `array` | 表单体，自动设 `Content-Type: application/x-www-form-urlencoded` |
| `body` | `string\|StreamInterface` | 原始请求体 |
| `version` | `string` | HTTP 协议版本，如 `'1.1'`、`'2'` |
| `timeout` | `float` | **仅本次请求**生效的超时（秒） |
| `transport` | `TransportOptions\|array` | **仅本次请求**生效的传输配置 |

> `json`、`form`、`body` **三者互斥**，同时传入会抛出 `ConfigurationException`。
>
> `timeout` 与 `transport` 属于作用域选项：执行期间写入上下文，请求结束后自动还原。

---

## HttpResponse

`Kode\HttpClient\Response\HttpResponse`，PSR-7 `ResponseInterface` 的装饰器，在完整实现 PSR-7 的基础上提供便捷方法。

### 构造与解包

```php
public static function wrap(ResponseInterface $response): self
public function unwrap(): ResponseInterface
```

### 便捷方法

| 方法 | 返回 | 说明 |
|------|------|------|
| `text()` | `string` | 响应体全文 |
| `json(bool $associative = true, int $depth = 512)` | `mixed` | 解析 JSON，失败抛 `ResponseFormatException` |
| `array()` | `array` | 解析 JSON 并断言为数组 |
| `status()` | `int` | 状态码 |
| `successful()` | `bool` | 2xx |
| `ok()` | `bool` | 恰为 200 |
| `redirect()` | `bool` | 3xx |
| `clientError()` | `bool` | 4xx |
| `serverError()` | `bool` | 5xx |
| `failed()` | `bool` | 4xx 或 5xx |
| `header(string $name)` | `string` | 单个响应头的行值 |

其余 PSR-7 方法（`getStatusCode`、`getHeaders`、`getBody`、`withHeader` 等）均按标准实现。

---

## Factory

`Kode\HttpClient\Factory`，把扁平配置翻译为驱动 + 中间件栈组合。

### create

```php
public static function create(array $options = []): HttpClient
```

传入未知配置键会抛出 `ConfigurationException`。完整支持的键（`Factory::SUPPORTED_OPTIONS`）：

#### 传输层

| 键 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `driver` | `string\|DriverInterface` | 自动探测 | `curl` / `fiber` / `swoole` / `swow` / `amp` 或驱动实例 |
| `base_uri` | `string` | — | 基础 URI |
| `transport` | `TransportOptions\|array` | — | 整体传输配置（优先级低于下列单项） |
| `timeout` | `float` | `30.0` | 总超时（秒） |
| `connect_timeout` | `float` | `10.0` | 连接超时（秒） |
| `follow_redirects` | `bool` | `true` | 是否跟随重定向 |
| `max_redirects` | `int` | `5` | 最大重定向次数 |
| `verify` | `bool\|string` | `true` | 校验 TLS 证书，或指定 CA 路径 |
| `proxy` | `string` | `null` | 代理地址 |
| `decode_content` | `bool` | `true` | 自动解压响应 |
| `user_agent` | `string` | 内置值 | User-Agent |
| `curl_options` | `array` | `[]` | 透传的原生 curl 选项 |
| `psr17` | `object` | 自动探测 | 自定义 PSR-17 工厂 |

#### 中间件层

| 键 | 类型 | 说明 |
|------|------|------|
| `headers` | `array<string, string>` | 默认请求头（`HeadersMiddleware`） |
| `logger` | `callable\|Psr\Log\LoggerInterface` | 日志（`LoggingMiddleware`） |
| `retries` | `int` | 最大重试次数，默认 `3`；设为 `0` 关闭重试 |
| `retry` | `array` | 重试细化配置，见下 |
| `cache` | `bool\|array` | 响应缓存，见下 |
| `rate_limit` | `array` | 令牌桶限流，见下 |
| `circuit_breaker` | `bool\|array` | 熔断，见下 |
| `auth` | `array` | 认证，见下 |
| `trace` | `bool\|array` | 链路追踪传播，见下 |
| `middleware` | `iterable<MiddlewareInterface>` | 追加的自定义中间件 |

**`retry` 子键：** `max_retries`、`initial_backoff`(ms, 100)、`backoff_multiplier`(2.0)、`max_backoff`(ms, 10000)、`status_codes`、`retry_non_idempotent`(false)、`respect_retry_after`(true)。

**`cache` 子键：** `ttl`(300)、`max_entries`(256)、`vary`、`respect_cache_control`(true)。

**`rate_limit` 子键：** `capacity`(10)、`rate`(1.0)、`blocking`(true)、`max_wait`(5.0)。

**`circuit_breaker` 子键：** `failure_threshold`(5)、`reset_timeout`(30.0)、`success_threshold`(1)。

**`auth` 子键：** `type`（`bearer` / `api_key` / `basic`）、`credential`（或 `token`）、`header`（api_key 用，默认 `X-API-Key`）、`username` / `password`（basic 用）。

**`trace` 子键：** `propagate_response`(false)。

> **中间件执行顺序**（按加入栈的先后）：
> `logger` → `circuit_breaker` → `retry` → `cache` → `rate_limit` → `auth` → `headers` → `trace` → `timeout` → 自定义 `middleware`。

### 其他工厂方法

```php
// 无中间件的极简客户端（仅传输层配置生效）
public static function createSimple(array $options = []): HttpClient

// 指定中间件栈
public static function createWithMiddleware(...): HttpClient

// 指定驱动的快捷入口
public static function createFiber(array $options = []): HttpClient
public static function createSwoole(array $options = []): HttpClient
public static function createSwow(array $options = []): HttpClient
public static function createAmp(array $options = []): HttpClient

// 低层构件
public static function createTransportOptions(array $options): TransportOptions
public static function createDriver(...): DriverInterface
public static function detectDriver(?TransportOptions $transport = null): DriverInterface
public static function createMiddlewareStack(array $options): MiddlewareStack
public static function assertOptions(array $options): void

// 各驱动在当前环境的可用性：array<string, bool>，形如 ['curl' => true, 'swoole' => false, ...]
public static function availableDrivers(): array
```

> `createSimple()` 不挂载任何中间件，因此配合并发驱动时 `supportsParallel()` 为 `true`，可获得真正的并行性能。

---

## TransportOptions

`Kode\HttpClient\Config\TransportOptions`，传输层配置值对象。

```php
public function __construct(
    public float $timeout = 30.0,
    public float $connectTimeout = 10.0,
    public bool $followRedirects = true,
    public int $maxRedirects = 5,
    public bool|string $verify = true,
    public ?string $proxy = null,
    public bool $decodeContent = true,
    public string $userAgent = self::DEFAULT_USER_AGENT,
    public array $defaultHeaders = [],
    public array $curlOptions = [],
)

public static function fromArray(array $options): self
public function with(array $overrides): self   // 派生新实例
public function toArray(): array
```

---

## Context

`Kode\HttpClient\Context\Context`，基于 `kode/context` ^3.1 的**静态**上下文助手，用于在请求生命周期内传递超时、重试计数、请求 ID 等信息。

> 注意：这是静态 API，不是可实例化的对象。

```php
public static function getTimeout(): ?float
public static function setTimeout(float $timeout): void
public static function clearTimeout(): void

public static function getRetryCount(): int
public static function setRetryCount(int $retryCount): void
public static function incrementRetryCount(): int

public static function getStartTime(): ?float
public static function setStartTime(float $startTime): void
public static function getElapsedTime(): ?float

public static function getRequestId(): ?string
public static function setRequestId(string $requestId): void

public static function rawTransportOptions(): ?TransportOptions
public static function getTransportOptions(): TransportOptions
public static function setTransportOptions(?TransportOptions $options): void

public static function initialize(array $options = []): string  // 返回请求 ID
public static function clear(): void
public static function export(): array
public static function import(array $data): void
```

分布式追踪相关能力（`startTrace()`、`toHeaders()`、`fromHeaders()`、`setCorrelationId()` 等）由底层 `Kode\Context\Context` 直接提供，并被 [`TracingMiddleware`](#tracingmiddleware) 使用。

---

## 中间件

所有中间件实现 `Kode\HttpClient\Middleware\MiddlewareInterface`。

### MiddlewareInterface

```php
public function process(RequestInterface $request, callable $next): ResponseInterface
```

`$next` 签名为 `fn(RequestInterface $request): ResponseInterface`。

### MiddlewareStack

`Kode\HttpClient\Middleware\MiddlewareStack`，实现 `Countable`、`IteratorAggregate`。

```php
public function __construct(iterable $middlewares = [])

public function add(MiddlewareInterface $middleware): self       // 追加（原地）
public function addMany(iterable $middlewares): self
public function prepend(MiddlewareInterface $middleware): self   // 前插
public function with(MiddlewareInterface ...$middleware): self   // 派生新栈（不可变）
public function clear(): self
public function handle(RequestInterface $request, callable $handler): ResponseInterface
```

### AuthMiddleware

```php
public function __construct(
    string $type,
    string|callable $credential,
    string $apiKeyHeader = 'X-API-Key',
)

public static function bearer(string|callable $token): self
public static function apiKey(string|callable $apiKey, string $header = 'X-API-Key'): self
public static function basic(string $username, string $password): self
```

`$credential` 支持 `callable`，可实现令牌懒加载 / 自动刷新。类型常量：`TYPE_BEARER`、`TYPE_API_KEY`、`TYPE_BASIC`。

### RetryMiddleware

```php
public function __construct(
    int $maxRetries = 3,
    int $initialBackoff = 100,          // 毫秒
    float $backoffMultiplier = 2.0,
    int $maxBackoff = 10000,            // 毫秒
    array $retryStatusCodes = self::DEFAULT_RETRY_STATUS_CODES,
    bool $retryNonIdempotent = false,
    bool $respectRetryAfter = true,
    ?callable $decider = null,
    ?callable $sleeper = null,
)
```

指数退避 + 抖动。默认只重试幂等方法；`$respectRetryAfter` 会遵循响应的 `Retry-After` 头。`$decider` 可自定义"是否重试"判定，`$sleeper` 便于测试注入。

### CacheMiddleware

```php
public function __construct(
    int $defaultTtl = 300,
    int $maxEntries = 256,
    array $varyHeaders = self::DEFAULT_VARY_HEADERS,
    bool $respectCacheControl = true,
)

public function clearCache(?string $cacheKey = null): void
public function getCacheStats(): array
```

LRU 淘汰，仅缓存安全方法。

### RateLimitMiddleware

```php
public function __construct(
    int $capacity = 10,
    float $rate = 1.0,        // 每秒生成令牌数
    bool $blocking = false,
    float $maxWait = 0.0,     // 秒
    ?callable $sleeper = null,
)

public function getTokens(): float
public function getCapacity(): int
```

令牌桶算法。`$blocking = false` 时无令牌立即抛出 `RateLimitException`；为 `true` 时最多等待 `$maxWait` 秒。

> 经 `Factory` 创建时，`blocking` 默认为 `true`、`max_wait` 默认为 `5.0`。

### CircuitBreakerMiddleware

```php
public function __construct(
    int $failureThreshold = 5,
    float $resetTimeout = 30.0,
    int $successThreshold = 1,
    array $failureStatusCodes = self::DEFAULT_FAILURE_STATUS_CODES,
)

public function stateOf(string $scope): string   // CLOSED / OPEN / HALF_OPEN
public function snapshot(): array
public function reset(?string $scope = null): void
```

按 host 维度隔离状态机。熔断打开时抛出 `CircuitBreakerOpenException`。

### TimeoutMiddleware

```php
public function __construct(
    float $defaultTimeout = 30.0,
    ?float $connectTimeout = null,
)

public function defaultOptions(): TransportOptions
```

上下文中已有超时设置时优先使用上下文的值。

### HeadersMiddleware

```php
public function __construct(
    array $headers,
    bool $override = false,   // false 时不覆盖请求已有的同名头
)
```

### LoggingMiddleware

```php
public function __construct(
    callable|object $logger,   // callable 或 PSR-3 LoggerInterface
    string $level = 'info',
    bool $logBody = false,
    int $bodyLimit = 512,
)
```

不硬依赖 `psr/log`：传入 `callable` 时签名为 `fn(string $message, string $level): void`。

### TracingMiddleware

```php
public function __construct(
    bool $propagateResponse = false,
)
```

基于 `kode/context` 3.x 的分布式追踪传播：自动把当前上下文的 W3C `traceparent` / `tracestate` 及 `X-Context-*` 系列头注入出站请求。`$propagateResponse = true` 时，会把下游响应头回写进本地上下文。

无活跃 trace 时安全降级（不注入相关头），可无条件启用。

---

## 驱动

### DriverInterface

```php
public function sendRequest(RequestInterface $request): ResponseInterface
```

### ConcurrentDriverInterface

```php
interface ConcurrentDriverInterface extends DriverInterface
{
    /** @return array<array-key, ResponseInterface|\Throwable> */
    public function sendConcurrent(array $requests): array;
}
```

### 内置实现

| 驱动 | 类 | 并发机制 | 要求 |
|------|------|----------|------|
| `curl` | `CurlDriver` | `curl_multi` | ext-curl |
| `fiber` | `FiberDriver` | Fiber + `curl_multi` | PHP 8.3+ / ext-curl |
| `swoole` | `SwooleDriver` | 原生协程 | ext-swoole |
| `swow` | `SwowDriver` | 原生协程 | ext-swow |
| `amp` | `AmpDriver` | 事件循环 | amphp/http-client |

全部实现 `ConcurrentDriverInterface`。`Factory::detectDriver()` 按运行环境自动挑选，`Factory::availableDrivers()` 返回当前可用列表。

---

## 异常

所有异常都实现 PSR-18 的 `Psr\Http\Client\ClientExceptionInterface`（`ResponseFormatException` 除外，它继承自 `HttpException`）。

| 异常 | 继承 | 触发场景 |
|------|------|----------|
| `HttpException` | `\Exception` | 异常基类 |
| `NetworkException` | `HttpException`，实现 `NetworkExceptionInterface` | 连接失败、DNS 错误等 |
| `TimeoutException` | `HttpException`，实现 `NetworkExceptionInterface` | 请求超时 |
| `RequestException` | `HttpException`，实现 `RequestExceptionInterface` | 请求本身有问题 |
| `ResponseFormatException` | `HttpException` | `json()` / `array()` 解析失败 |
| `ConfigurationException` | `\InvalidArgumentException` | 配置项或请求选项非法 |
| `RateLimitException` | `\RuntimeException` | 限流拒绝 |
| `CircuitBreakerOpenException` | `\RuntimeException` | 熔断器处于打开状态 |

因此可以统一捕获：

```php
try {
    $response = $client->get('https://example.com');
} catch (\Psr\Http\Client\ClientExceptionInterface $e) {
    // 覆盖本库抛出的全部异常
}
```
