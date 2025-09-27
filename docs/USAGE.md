# 使用指南

## 目录
- [基本用法](#基本用法)
- [上下文管理](#上下文管理)
- [中间件系统](#中间件系统)
  - [认证中间件](#认证中间件)
  - [限流中间件](#限流中间件)
  - [缓存中间件](#缓存中间件)
  - [重试中间件](#重试中间件)
  - [超时中间件](#超时中间件)
  - [日志中间件](#日志中间件)
- [驱动支持](#驱动支持)
- [高级用法](#高级用法)

## 基本用法

### 创建客户端

```php
use Kode\HttpClient\Factory;
use GuzzleHttp\Psr7\Request;

// 使用工厂方法创建客户端（推荐）
$client = Factory::create();

// 手动创建客户端
$client = new \Kode\HttpClient\HttpClient();
```

### 发送请求

```php
use GuzzleHttp\Psr7\Request;

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求
$response = $client->sendRequest($request);

echo $response->getStatusCode(); // 200
echo $response->getBody();       // 响应内容
```

## 上下文管理

上下文用于在请求处理过程中传递额外的信息。

### 基本用法

```php
use Kode\HttpClient\Factory;
use Kode\HttpClient\Context\Context;
use GuzzleHttp\Psr7\Request;

// 创建客户端
$client = Factory::create();

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 创建上下文
$context = new Context();
$context = $context->withTimeout(5.0); // 5秒超时
$context = $context->withRetryCount(3); // 最大重试次数

// 发送请求
$response = $client->sendRequest($request, $context);
```

### 可用的上下文方法

- `getTimeout()` 和 `withTimeout()`: 获取和设置请求超时时间
- `getRetryCount()` 和 `withRetryCount()`: 获取和设置重试次数

## 中间件系统

### 认证中间件

支持 Bearer Token 和 API Key 认证：

```php
use Kode\HttpClient\Middleware\AuthMiddleware;

// Bearer Token 认证
$middleware = AuthMiddleware::bearer('your-bearer-token');

// API Key 认证
$middleware = AuthMiddleware::apiKey('your-api-key', 'X-API-Key');
```

通过工厂配置：

```php
$client = Factory::create([
    'auth' => [
        'type' => 'bearer',
        'credential' => 'your-bearer-token'
    ]
]);
```

### 限流中间件

使用令牌桶算法实现请求频率限制：

```php
use Kode\HttpClient\Middleware\RateLimitMiddleware;

$middleware = new RateLimitMiddleware(
    10,  // 桶的容量
    1    // 每秒生成的令牌数
);
```

通过工厂配置：

```php
$client = Factory::create([
    'rate_limit' => [
        'capacity' => 10,
        'rate' => 1
    ]
]);
```

### 缓存中间件

自动缓存响应以提高性能：

```php
use Kode\HttpClient\Middleware\CacheMiddleware;

$middleware = new CacheMiddleware(60); // 60秒缓存时间
```

通过工厂配置：

```php
$client = Factory::create([
    'cache_ttl' => 60  // 缓存时间（秒）
]);
```

### 重试中间件

增强版重试中间件支持对所有异常类型的重试：

```php
use Kode\HttpClient\Middleware\RetryMiddleware;

$middleware = new RetryMiddleware(
    3,      // 最大重试次数
    100,    // 初始退避时间（毫秒）
    2.0     // 退避乘数
);
```

通过工厂配置：

```php
$client = Factory::create([
    'retries' => 3  // 最大重试次数
]);
```

### 超时中间件

控制请求超时时间：

```php
use Kode\HttpClient\Middleware\TimeoutMiddleware;

$middleware = new TimeoutMiddleware(5.0); // 5秒超时
```

通过工厂配置：

```php
$client = Factory::create([
    'timeout' => 5.0  // 超时时间（秒）
]);
```

### 日志中间件

记录请求和响应信息：

```php
use Kode\HttpClient\Middleware\LoggingMiddleware;

$logger = function (string $message) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
};

$middleware = new LoggingMiddleware($logger);
```

通过工厂配置：

```php
$client = Factory::create([
    'logger' => function (string $message) {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    }
]);
```

## 驱动支持

| 运行环境 | 推荐驱动 | 说明 |
|---------|----------|------|
| Swoole 启用 | `SwooleDriver` | 高性能、原生协程支持 |
| Amp 可用 | `AmpDriver` | 基于事件循环的纯 PHP 实现 |
| 默认环境 | `CurlDriver` | 基于 curl 扩展的同步实现 |

## 高级用法

### 手动添加中间件

```php
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Middleware\TimeoutMiddleware;
use Kode\HttpClient\Middleware\RetryMiddleware;

$client = new HttpClient();

// 添加中间件
$client->addMiddleware(new TimeoutMiddleware(5.0));
$client->addMiddleware(new RetryMiddleware(3));

// 中间件会按照添加顺序执行
```

### 自定义中间件

实现 `MiddlewareInterface` 接口创建自定义中间件：

```php
use Kode\HttpClient\Middleware\MiddlewareInterface;
use Kode\HttpClient\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CustomMiddleware implements MiddlewareInterface
{
    public function process(
        RequestInterface $request, 
        Context $context, 
        callable $next
    ): ResponseInterface {
        // 在调用下一个中间件之前执行的代码
        
        // 调用下一个中间件
        $response = $next($request, $context);
        
        // 在下一个中间件返回后执行的代码
        
        return $response;
    }
}
```

### 获取缓存统计

```php
use Kode\HttpClient\Middleware\CacheMiddleware;

$cacheMiddleware = new CacheMiddleware(60);
$client->addMiddleware($cacheMiddleware);

// 发送一些请求...

// 获取缓存统计信息
$stats = $cacheMiddleware->getCacheStats();
print_r($stats);
```