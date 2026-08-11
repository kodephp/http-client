# 使用指南

适用版本：**2.5.0**（PHP >= 8.3）

## 目录

- [安装](#安装)
- [基本用法](#基本用法)
- [请求选项](#请求选项)
- [响应处理](#响应处理)
- [工厂配置](#工厂配置)
- [中间件](#中间件)
- [上下文管理](#上下文管理)
- [并发请求](#并发请求)
- [驱动选择](#驱动选择)
- [自定义驱动与测试](#自定义驱动与测试)
- [错误处理](#错误处理)

---

## 安装

```bash
composer require kode/http-client
```

要求 PHP >= 8.3，并安装一个 PSR-17 实现（如 `guzzlehttp/psr7` 或 `nyholm/psr7`）。

---

## 基本用法

### 创建客户端

推荐通过 `Factory` 创建（自动探测驱动、装配中间件）：

```php
use Kode\HttpClient\Factory;

$client = Factory::create([
    'timeout' => 30.0,
    'retries' => 3,
]);
```

`Factory::create()` 返回的是 `Kode\HttpClient\HttpClientInterface`（实际为 `HttpClient`）。

### 发送请求

两类入口：

1. **快捷方法**——方法名即 HTTP 动词，选项数组支持 `query` / `headers` / `json` / `form` / `body` 等：

```php
$response = $client->get('https://httpbin.org/get', [
    'query' => ['page' => 1, 'size' => 20],
]);

$response = $client->post('https://httpbin.org/post', [
    'json' => ['name' => 'kode', 'active' => true],
]);

$response = $client->post('https://httpbin.org/post', [
    'form' => ['field' => 'value'],
]);
```

2. **`request()`**——任意方法 + 选项：

```php
$response = $client->request('PATCH', 'https://httpbin.org/patch', [
    'json' => ['status' => 'done'],
]);
```

3. **PSR-18 `sendRequest()`**——传入已构建的 PSR-7 请求：

```php
use GuzzleHttp\Psr7\Request;

$request = new Request('GET', 'https://httpbin.org/get');
$response = $client->sendRequest($request);
```

> `json`、`form`、`body` **三者互斥**，同时传入会抛 `ConfigurationException`。

### 基础 URI

`Factory` 接受 `base_uri`；之后传入相对路径即可：

```php
$client = Factory::create([
    'base_uri' => 'https://api.example.com',
]);

$response = $client->get('/v1/users');   // => https://api.example.com/v1/users
```

---

## 请求选项

`request()` / 各动词方法接受的选项键：

| 选项 | 类型 | 说明 |
|------|------|------|
| `query` | `array\|string` | 查询参数（自动编码合并） |
| `headers` | `array` | 请求头 |
| `json` | `mixed` | JSON 体，自动设置 `Content-Type` |
| `form` | `array` | 表单体，自动设置 `Content-Type` |
| `body` | `string\|StreamInterface` | 原始体 |
| `version` | `string` | 协议版本，如 `'1.1'`、`'2'` |
| `timeout` | `float` | 仅本次请求的超时（秒） |
| `transport` | `TransportOptions\|array` | 仅本次请求的传输配置 |

`timeout` 与 `transport` 只对当前请求生效，执行后上下文自动还原。

```php
$client->get('/slow', ['timeout' => 2.0]);   // 单次短时超时
```

完整清单见 [API 文档](API.md#请求选项)。

---

## 响应处理

所有方法返回 `Kode\HttpClient\Response\HttpResponse`（PSR-7 装饰器）。

```php
$response = $client->get('https://httpbin.org/get');

$response->status();          // 200
$response->successful();      // true（2xx）
$response->ok();              // true（恰为 200）
$response->clientError();     // 4xx
$response->serverError();     // 5xx
$response->failed();          // 4xx 或 5xx

$body = $response->text();                // 原始字符串
$data = $response->json();                // 解析为对象/数组
$arr  = $response->array();               // 解析为数组

$token = $response->header('X-Request-Id');   // 单个头

$psr7 = $response->unwrap();              // 取回原始 PSR-7 响应
```

---

## 工厂配置

`Factory::create()` 支持传输层与中间件层两大类配置，传入未知键会抛 `ConfigurationException`。

### 认证

```php
$client = Factory::create([
    'auth' => [
        'type' => 'bearer',
        'token' => 'your-token',
    ],
]);

// 或 API Key / Basic
$client = Factory::create([
    'auth' => ['type' => 'api_key', 'credential' => 'key', 'header' => 'X-Api-Key'],
]);
$client = Factory::create([
    'auth' => ['type' => 'basic', 'username' => 'u', 'password' => 'p'],
]);
```

凭据支持 `callable`，可延迟加载或在每次请求时刷新令牌：

```php
'auth' => ['type' => 'bearer', 'credential' => fn() => TokenStore::fresh()],
```

### 重试

```php
$client = Factory::create([
    'retries' => 3,                    // 快捷次数
    'retry' => [                       // 精细配置
        'initial_backoff' => 200,      // 毫秒
        'backoff_multiplier' => 2.0,
        'max_backoff' => 5000,
        'status_codes' => [429, 502, 503],
        'retry_non_idempotent' => false,
        'respect_retry_after' => true,
    ],
]);
```

### 缓存

```php
$client = Factory::create([
    'cache' => [
        'ttl' => 60,
        'max_entries' => 256,
        'respect_cache_control' => true,
    ],
]);
```

### 限流（令牌桶）

```php
$client = Factory::create([
    'rate_limit' => [
        'capacity' => 10,
        'rate' => 1.0,        // 每秒 1 个令牌
        'blocking' => true,    // true：等待直至拿到令牌；false：直接抛 RateLimitException
        'max_wait' => 5.0,     // 最多等待秒数
    ],
]);
```

### 熔断

```php
$client = Factory::create([
    'circuit_breaker' => [
        'failure_threshold' => 5,
        'reset_timeout' => 30.0,
        'success_threshold' => 1,
    ],
]);
```

### 日志

```php
$client = Factory::create([
    'logger' => function (string $message, string $level = 'info'): void {
        echo "[$level] $message\n";
    },
]);

// 或传入 PSR-3 LoggerInterface
$client = Factory::create(['logger' => $psr3Logger]);
```

### 链路追踪

```php
$client = Factory::create([
    'trace' => ['propagate_response' => true],
]);
```

自动把当前 `kode/context` 的 W3C `traceparent` / `tracestate` 与 `X-Context-*` 头注入出站请求；`propagate_response` 还会回写下游客体的上下文。无活跃 trace 时安全降级。

### 默认请求头

```php
$client = Factory::create([
    'headers' => ['Accept' => 'application/json'],
]);
```

### 追加自定义中间件

```php
$client = Factory::create([
    'middleware' => [new MyCustomMiddleware()],
]);
```

配置键完整清单见 [API 文档](API.md#factory)。

---

## 中间件

### 手动组装栈

`HttpClient` 本身不可变，使用 `MiddlewareStack` 后通过 `withMiddleware()` 派生新客户端：

```php
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\LoggingMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;
use Kode\HttpClient\Factory;

// 需要一个已构造的客户端（驱动 + 已有栈）
$base = Factory::createSimple(['timeout' => 5.0]);

$client = $base->withMiddleware(
    new LoggingMiddleware(fn($m, $l = 'info') => print_r($m)),
    AuthMiddleware::bearer('token'),
    new RetryMiddleware(maxRetries: 3),
);
```

> `Factory::create()` 已经把这些中间件按合理顺序装配好了，多数场景无需手动组装。

### 自定义中间件

```php
use Kode\HttpClient\Middleware\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AddRequestIdMiddleware implements MiddlewareInterface
{
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $request = $request->withHeader('X-Request-Id', uniqid());

        return $next($request);
    }
}
```

### 中间件执行顺序

经 `Factory::create()` 时顺序为：
`logger` → `circuit_breaker` → `retry` → `cache` → `rate_limit` → `auth` → `headers` → `trace` → `timeout` → 自定义 `middleware`。

---

## 上下文管理

上下文是**静态**助手（基于 `kode/context` ^3.1），用于在一个请求生命周期内临时传递配置：

```php
use Kode\HttpClient\Context\Context;

Context::setTimeout(2.0);        // 超时
Context::setRetryCount(3);       // 重试次数
Context::setRequestId('req-123');

$response = $client->get('/path');   // 使用上述上下文

Context::clear();               // 用完清理（通常框架/请求结束时调用）
```

更高阶的能力（分布式 trace、`startTrace()`、`toHeaders()`/`fromHeaders()`）由底层 `Kode\Context\Context` 提供，并被 `TracingMiddleware` 使用。完整方法见 [API 文档](API.md#context)。

---

## 并发请求

当驱动实现 `ConcurrentDriverInterface` **且**未启用任何中间件时，`supportsParallel()` 为 `true`，可真正并行。

### sendConcurrent

传入已构建的 PSR-7 请求，**全部落定**语义——单项失败不影响其他项：

```php
use GuzzleHttp\Psr7\Request;

$requests = [
    new Request('GET', 'https://httpbin.org/get'),
    new Request('GET', 'https://httpbin.org/status/500'),
];

$results = $client->sendConcurrent($requests);
// $results 的键与 $requests 一一对应：
//   成功项为 HttpResponse，失败项为 Throwable
```

### pool

同样的语义，但接受 `[method, uri, options]` 三元组，少一步手工构建：

```php
$specs = [
    ['GET', 'https://httpbin.org/get', ['query' => ['a' => 1]]],
    ['POST', 'https://httpbin.org/post', ['json' => ['x' => 2]]],
];

$results = $client->pool($specs);
foreach ($results as $i => $result) {
    if ($result instanceof \Throwable) {
        echo "请求 #$i 失败：{$result->getMessage()}\n";
    } else {
        echo "请求 #$i 成功：{$result->status()}\n";
    }
}
```

若需要最高并发性能且不需要中间件，用 `Factory::createSimple()` 创建客户端即可满足 `supportsParallel()`。

---

## 驱动选择

`Factory` 自动探测当前运行环境：

| 环境 | 选用驱动 | 并发机制 |
|------|----------|----------|
| 安装 ext-swoole | `SwooleDriver` | 原生协程 |
| 安装 ext-swow | `SwowDriver` | 原生协程 |
| 安装 ext-curl（默认） | `CurlDriver` / `FiberDriver` | curl_multi / Fiber |

也可用快捷工厂直接指定：

```php
Factory::createFiber([...]);
Factory::createSwoole([...]);
Factory::createSwow([...]);
Factory::createAmp([...]);

// 各驱动在当前环境是否可用：['curl' => true, 'fiber' => true, 'swoole' => false, ...]
Factory::availableDrivers();
```

`AmpDriver` 需要额外安装 `amphp/http-client`。

---

## 自定义驱动与测试

实现 `Kode\HttpClient\Driver\DriverInterface`（如需并发，再实现 `ConcurrentDriverInterface`），即可注入 `HttpClient`：

```php
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Driver\DriverInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeDriver implements DriverInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // 返回桩响应，便于离线测试
        return new \GuzzleHttp\Psr7\Response(200, [], '{"ok":true}');
    }
}

$client = new HttpClient(new FakeDriver());
$response = $client->get('https://example.test/x');  // 离线、确定性
```

这同时也是单元测试惯用法：用桩驱动替换网络，使测试不依赖外部服务。测试支持目录见 `tests/Support/`（含 `RecordingDriver`）。

---

## 错误处理

本库所有异常均实现 PSR-18 的 `Psr\Http\Client\ClientExceptionInterface`：

```php
use Psr\Http\Client\ClientExceptionInterface;

try {
    $response = $client->get('https://example.com');
} catch (ClientExceptionInterface $e) {
    // 覆盖网络、超时、配置、限流、熔断等全部抛错
}
```

更精细捕获：

```php
use Kode\HttpClient\Exception\TimeoutException;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RateLimitException;
use Kode\HttpClient\Exception\CircuitBreakerOpenException;
use Kode\HttpClient\Exception\ConfigurationException;

try {
    $client->get('/path');
} catch (TimeoutException $e) {
    // 超时
} catch (RateLimitException $e) {
    // 被限流
} catch (CircuitBreakerOpenException $e) {
    // 熔断打开
} catch (ConfigurationException $e) {
    // 配置/选项非法
}
```

`$response->json()` 解析失败时抛 `ResponseFormatException`，单独捕获即可。
