# kode/http-client

一个现代化、高性能的 PHP HTTP 客户端，支持多运行时环境（FPM、CLI、Swoole、Swow、Fiber）。

## 特性

- ✅ 多运行时支持（FPM、CLI、Swoole、Swow、Fiber）
- ✅ 自动环境检测与驱动切换
- ✅ PSR-7/PSR-18 兼容
- ✅ 支持请求上下文传递（使用 kode/context）
- ✅ 中间件支持（重试、超时、日志等）
- ✅ 异常处理
- ✅ 简洁的 API

## 安装

```bash
composer require kode/http-client
```

## 使用

### 基本使用

```php
use Kode\HttpClient\Factory;
use GuzzleHttp\Psr7\Request;

// 创建客户端
$client = Factory::create();

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求
$response = $client->sendRequest($request);

echo $response->getStatusCode(); // 200
echo $response->getBody();       // 响应内容
```

### 使用上下文

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

### 增强的上下文功能

我们扩展了上下文功能，添加了以下方法：

- `getTimeout()` 和 `withTimeout()`: 获取和设置请求超时时间
- `getRetryCount()` 和 `withRetryCount()`: 获取和设置重试次数

### 使用中间件

```php
use Kode\HttpClient\Factory;
use Kode\Context\Context;
use GuzzleHttp\Psr7\Request;

// 创建带配置的客户端
$client = Factory::create([
    'timeout' => 10.0,     // 默认超时时间
    'retries' => 3,        // 最大重试次数
    'logger' => function (string $message) {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    },
    'auth' => [            // 认证配置
        'type' => 'bearer',
        'credential' => 'your-bearer-token'
    ],
    'rate_limit' => [      // 限流配置
        'capacity' => 10,
        'rate' => 1
    ],
    'cache' => true        // 启用缓存
]);

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求（将自动应用所有配置的中间件）
$response = $client->sendRequest($request);
```

#### 认证中间件

支持 Bearer Token 和 API Key 认证：

```php
// Bearer Token 认证
$client = Factory::create([
    'auth' => [
        'type' => 'bearer',
        'credential' => 'your-bearer-token'
    ]
]);

// API Key 认证
$client = Factory::create([
    'auth' => [
        'type' => 'api_key',
        'credential' => 'your-api-key',
        'header' => 'X-API-Key'  // 可选，默认为 X-API-Key
    ]
]);
```

#### 限流中间件

使用令牌桶算法实现请求频率限制：

```php
$client = Factory::create([
    'rate_limit' => [
        'capacity' => 10,  // 桶的容量
        'rate' => 1        // 每秒生成的令牌数
    ]
]);
```

#### 缓存中间件

自动缓存响应以提高性能：

```php
$client = Factory::create([
    'cache' => true  // 启用缓存
]);
```

#### 重试中间件（增强版）

改进的重试中间件现在支持对所有异常类型的重试，而不仅仅是网络异常：

```php
// 手动添加重试中间件
use Kode\HttpClient\Middleware\RetryMiddleware;

$client = new \Kode\HttpClient\HttpClient();
$client->addMiddleware(new RetryMiddleware(
    3,      // 最大重试次数
    100,    // 初始退避时间（毫秒）
    2.0     // 退避乘数
));
```

## 驱动支持

| 运行环境 | 推荐驱动 | 说明 |
|---------|----------|------|
| Swoole 启用 | `SwooleDriver` | 高性能、原生协程支持 |
| Amp 可用 | `AmpDriver` | 基于事件循环的纯 PHP 实现 |
| 默认环境 | `CurlDriver` | 基于 curl 扩展的同步实现 |

## 文档

- [使用指南](docs/USAGE.md) - 详细说明如何使用客户端的所有功能
- [API 文档](docs/API.md) - 完整的 API 参考

## 许可证

Apache-2.0