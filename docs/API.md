# API 文档

## 目录
- [HttpClient](#httpclient)
- [Factory](#factory)
- [Context](#context)
- [中间件](#中间件)
  - [MiddlewareInterface](#middlewareinterface)
  - [AuthMiddleware](#authmiddleware)
  - [CacheMiddleware](#cachemiddleware)
  - [RateLimitMiddleware](#ratelimitmiddleware)
  - [RetryMiddleware](#retrymiddleware)
  - [TimeoutMiddleware](#timeoutmiddleware)
  - [LoggingMiddleware](#loggingmiddleware)
  - [MiddlewareStack](#middlewarestack)
- [驱动](#驱动)
  - [DriverInterface](#driverinterface)
  - [CurlDriver](#curldriver)
  - [SwooleDriver](#swooledriver)
  - [AmpDriver](#ampdriver)
- [异常](#异常)
  - [HttpException](#httpexception)
  - [NetworkException](#networkexception)
  - [RequestException](#requestexception)

## HttpClient

主客户端类，实现了 PSR-18 `ClientInterface`。

### 构造函数

```php
public function __construct(?DriverInterface $driver = null)
```

**参数:**
- `$driver`: 可选的驱动实例，默认会自动选择

### 方法

#### sendRequest

发送 PSR-7 请求。

```php
public function sendRequest(RequestInterface $request, ?Context $context = null): ResponseInterface
```

**参数:**
- `$request`: PSR-7 请求对象
- `$context`: 可选的上下文对象

**返回值:**
- PSR-7 响应对象

#### get

发送 GET 请求。

```php
public function get(string $uri, array $headers = []): ResponseInterface
```

**参数:**
- `$uri`: 请求 URI
- `$headers`: 请求头数组

**返回值:**
- PSR-7 响应对象

#### post

发送 POST 请求。

```php
public function post(string $uri, array $headers = [], $body = null): ResponseInterface
```

**参数:**
- `$uri`: 请求 URI
- `$headers`: 请求头数组
- `$body`: 请求体内容

**返回值:**
- PSR-7 响应对象

#### put

发送 PUT 请求。

```php
public function put(string $uri, array $headers = [], $body = null): ResponseInterface
```

**参数:**
- `$uri`: 请求 URI
- `$headers`: 请求头数组
- `$body`: 请求体内容

**返回值:**
- PSR-7 响应对象

#### delete

发送 DELETE 请求。

```php
public function delete(string $uri, array $headers = []): ResponseInterface
```

**参数:**
- `$uri`: 请求 URI
- `$headers`: 请求头数组

**返回值:**
- PSR-7 响应对象

#### addMiddleware

添加中间件到客户端。

```php
public function addMiddleware(MiddlewareInterface $middleware): void
```

**参数:**
- `$middleware`: 中间件实例

#### setDriver

设置客户端驱动。

```php
public function setDriver(DriverInterface $driver): void
```

**参数:**
- `$driver`: 驱动实例

## Factory

工厂类，用于创建配置好的客户端实例。

### create

创建配置好的客户端实例。

```php
public static function create(array $config = []): HttpClientInterface
```

**参数:**
- `$config`: 配置数组，支持以下选项：
  - `timeout`: 超时时间（秒）
  - `retries`: 重试次数
  - `logger`: 日志回调函数
  - `auth`: 认证配置
  - `rate_limit`: 限流配置
  - `cache_ttl`: 缓存时间（秒）

**返回值:**
- `HttpClientInterface` 实例

## Context

上下文类，用于在请求处理过程中传递额外信息。

### 方法

#### getTimeout

获取超时时间。

```php
public function getTimeout(): ?float
```

**返回值:**
- 超时时间（秒）或 null

#### withTimeout

设置超时时间并返回新实例。

```php
public function withTimeout(float $timeout): self
```

**参数:**
- `$timeout`: 超时时间（秒）

**返回值:**
- 新的 Context 实例

#### getRetryCount

获取重试次数。

```php
public function getRetryCount(): ?int
```

**返回值:**
- 重试次数或 null

#### withRetryCount

设置重试次数并返回新实例。

```php
public function withRetryCount(int $retryCount): self
```

**参数:**
- `$retryCount`: 重试次数

**返回值:**
- 新的 Context 实例

## 中间件

### MiddlewareInterface

中间件接口。

#### process

处理请求。

```php
public function process(RequestInterface $request, Context $context, callable $next): ResponseInterface
```

**参数:**
- `$request`: 请求对象
- `$context`: 上下文对象
- `$next`: 下一个中间件的回调函数

**返回值:**
- 响应对象

### AuthMiddleware

认证中间件。

#### bearer

创建 Bearer Token 认证中间件。

```php
public static function bearer(string $token): self
```

**参数:**
- `$token`: Bearer Token

**返回值:**
- AuthMiddleware 实例

#### apiKey

创建 API Key 认证中间件。

```php
public static function apiKey(string $key, string $headerName = 'X-API-Key'): self
```

**参数:**
- `$key`: API Key
- `$headerName`: 头部名称，默认为 'X-API-Key'

**返回值:**
- AuthMiddleware 实例

### CacheMiddleware

缓存中间件。

#### 构造函数

```php
public function __construct(int $ttl = 300)
```

**参数:**
- `$ttl`: 缓存时间（秒），默认 300 秒

#### getCacheStats

获取缓存统计信息。

```php
public function getCacheStats(): array
```

**返回值:**
- 包含缓存统计信息的数组

### RateLimitMiddleware

限流中间件。

#### 构造函数

```php
public function __construct(int $capacity, int $rate)
```

**参数:**
- `$capacity`: 令牌桶容量
- `$rate`: 每秒生成的令牌数

### RetryMiddleware

重试中间件。

#### 构造函数

```php
public function __construct(int $maxRetries = 3, int $initialBackoff = 100, float $backoffMultiplier = 2.0)
```

**参数:**
- `$maxRetries`: 最大重试次数，默认 3
- `$initialBackoff`: 初始退避时间（毫秒），默认 100
- `$backoffMultiplier`: 退避乘数，默认 2.0

### TimeoutMiddleware

超时中间件。

#### 构造函数

```php
public function __construct(float $timeout)
```

**参数:**
- `$timeout`: 超时时间（秒）

### LoggingMiddleware

日志中间件。

#### 构造函数

```php
public function __construct(callable $logger)
```

**参数:**
- `$logger`: 日志回调函数

### MiddlewareStack

中间件栈，用于管理中间件链。

#### 构造函数

```php
public function __construct(array $middlewares = [])
```

**参数:**
- `$middlewares`: 中间件数组

#### add

添加中间件。

```php
public function add(MiddlewareInterface $middleware): void
```

**参数:**
- `$middleware`: 中间件实例

#### process

处理请求。

```php
public function process(RequestInterface $request, Context $context, callable $handler): ResponseInterface
```

**参数:**
- `$request`: 请求对象
- `$context`: 上下文对象
- `$handler`: 最终处理器回调函数

**返回值:**
- 响应对象

## 驱动

### DriverInterface

驱动接口。

#### sendRequest

发送请求。

```php
public function sendRequest(RequestInterface $request, ?Context $context = null): ResponseInterface
```

**参数:**
- `$request`: 请求对象
- `$context`: 可选的上下文对象

**返回值:**
- 响应对象

### CurlDriver

基于 cURL 的驱动实现。

#### 构造函数

```php
public function __construct()
```

### SwooleDriver

基于 Swoole 的驱动实现。

#### 构造函数

```php
public function __construct()
```

### AmpDriver

基于 Amp 的驱动实现。

#### 构造函数

```php
public function __construct()
```

## 异常

### HttpException

HTTP 异常基类。

#### 构造函数

```php
public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
```

### NetworkException

网络异常。

#### 构造函数

```php
public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
```

### RequestException

请求异常。

#### 构造函数

```php
public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
```