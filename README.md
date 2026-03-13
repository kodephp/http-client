# kode/http-client

一个现代化、高性能的 PHP HTTP 客户端，支持多运行时环境（FPM、CLI、Swoole、Swow、Fiber）。

## 特性

- ✅ **多运行时支持** - 自动检测运行环境，支持 FPM、CLI、Swoole、Swow、Fiber
- ✅ **自动驱动切换** - 根据环境自动选择最优驱动
- ✅ **PSR-7/PSR-18 兼容** - 完全遵循 PSR 标准
- ✅ **上下文管理** - 使用 `kode/context` 进行请求上下文传递
- ✅ **中间件机制** - 内置重试、超时、日志、认证、限流、缓存等中间件
- ✅ **PHP 8.1+ 支持** - 兼容 PHP 8.5 新特性
- ✅ **Fiber 支持** - 原生 Fiber 驱动，支持 kode/fibers 包集成
- ✅ **类型安全** - 完整的类型声明和静态分析支持

## 安装

```bash
composer require kode/http-client
```

## 快速开始

### 基本使用

```php
use Kode\HttpClient\Factory;
use GuzzleHttp\Psr7\Request;

// 创建客户端（自动选择最优驱动）
$client = Factory::create();

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求
$response = $client->sendRequest($request);

echo $response->getStatusCode(); // 200
echo $response->getBody();       // 响应内容
```

### 使用 Fiber 驱动

```php
use Kode\HttpClient\Factory;

// 创建 Fiber 驱动的客户端
$client = Factory::createFiber([
    'timeout' => 10.0,
    'retries' => 3,
]);

// 或指定驱动类型
$client = Factory::create([
    'driver' => Factory::DRIVER_FIBER,
]);
```

### 使用配置选项

```php
use Kode\HttpClient\Factory;

// 创建带配置的客户端
$client = Factory::create([
    'driver' => 'fiber',   // 驱动类型（auto|curl|swoole|amp|fiber）
    'timeout' => 10.0,     // 默认超时时间（秒）
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

// 发送请求
$request = new \GuzzleHttp\Psr7\Request('GET', 'https://httpbin.org/get');
$response = $client->sendRequest($request);
```

## 驱动支持

| 运行环境 | 推荐驱动 | 说明 |
|---------|----------|------|
| Fiber 环境 | `FiberDriver` | PHP 8.1+ 原生 Fiber 支持，自动协程切换 |
| Swoole 协程 | `SwooleDriver` | 高性能、原生协程支持 |
| Amp 环境 | `AmpDriver` | 基于事件循环的异步实现 |
| 默认环境 | `CurlDriver` | 基于 curl 扩展的同步实现 |

### 驱动选择优先级

自动模式下，驱动选择优先级为：

1. **SwooleDriver** - 如果在 Swoole 协程环境中
2. **FiberDriver** - 如果 PHP 8.1+ 且 curl 扩展可用
3. **AmpDriver** - 如果 Amp HTTP 客户端可用
4. **CurlDriver** - 默认回退

### 手动选择驱动

```php
use Kode\HttpClient\Factory;
use Kode\HttpClient\HttpClient;
use Kode\HttpClient\Driver\CurlDriver;
use Kode\HttpClient\Driver\FiberDriver;
use Kode\HttpClient\Driver\SwooleDriver;

// 方式一：通过工厂配置
$client = Factory::create(['driver' => Factory::DRIVER_FIBER]);

// 方式二：使用工厂快捷方法
$client = Factory::createFiber();
$client = Factory::createSwoole();
$client = Factory::createAmp();

// 方式三：手动实例化
$client = new HttpClient(new FiberDriver());
```

## 中间件

### 认证中间件

支持 Bearer Token 和 API Key 认证：

```php
use Kode\HttpClient\Middleware\AuthMiddleware;
use Kode\HttpClient\Middleware\MiddlewareStack;
use Kode\HttpClient\Factory;

// 方式一：通过工厂配置
$client = Factory::create([
    'auth' => [
        'type' => 'bearer',
        'credential' => 'your-bearer-token'
    ]
]);

// 方式二：手动添加中间件
$stack = new MiddlewareStack();
$stack->add(AuthMiddleware::bearer('your-bearer-token'));
// 或
$stack->add(AuthMiddleware::apiKey('your-api-key', 'X-API-Key'));

$client = Factory::createWithMiddleware($stack);
```

### 限流中间件

使用令牌桶算法实现请求频率限制：

```php
use Kode\HttpClient\Middleware\RateLimitMiddleware;

// 容量 10，每秒生成 1 个令牌
$middleware = new RateLimitMiddleware(10, 1);

// 阻塞模式（等待可用令牌）
$middleware = new RateLimitMiddleware(10, 1, true);
```

### 缓存中间件

自动缓存 GET 请求响应：

```php
use Kode\HttpClient\Middleware\CacheMiddleware;

// 默认缓存 300 秒
$middleware = new CacheMiddleware();

// 自定义缓存时间
$middleware = new CacheMiddleware(600); // 10 分钟

// 获取缓存统计
$stats = $middleware->getCacheStats();
// ['total' => 10, 'valid' => 8, 'expired' => 2]

// 清除缓存
$middleware->clearCache();
```

### 重试中间件

支持指数退避策略的自动重试：

```php
use Kode\HttpClient\Middleware\RetryMiddleware;

// 最多重试 3 次，初始退避 100ms，退避乘数 2.0
$middleware = new RetryMiddleware(3, 100, 2.0);
```

### 超时中间件

为请求设置超时时间：

```php
use Kode\HttpClient\Middleware\TimeoutMiddleware;

// 默认超时 30 秒
$middleware = new TimeoutMiddleware(30.0);
```

### 日志中间件

记录请求和响应信息：

```php
use Kode\HttpClient\Middleware\LoggingMiddleware;

$middleware = new LoggingMiddleware(function (string $message) {
    error_log($message);
});
```

## 上下文管理

使用 `kode/context` 进行上下文传递：

```php
use Kode\HttpClient\Context\Context;

// 设置超时时间
Context::setTimeout(5.0);

// 获取超时时间
$timeout = Context::getTimeout();

// 设置重试次数
Context::setRetryCount(3);

// 获取请求耗时
$elapsed = Context::getElapsedTime(); // 毫秒

// 初始化上下文
$requestId = Context::initialize([
    'timeout' => 10.0,
    'retry_count' => 3,
]);

// 清除上下文
Context::clear();
```

## 与 kode/fibers 集成

本包支持与 `kode/fibers` 包无缝集成：

```php
use Kode\Fibers\Fibers;
use Kode\HttpClient\Factory;
use GuzzleHttp\Psr7\Request;

// 在 Fiber 池中并发发送请求
$results = Fibers::batch(
    ['https://httpbin.org/get', 'https://httpbin.org/post'],
    fn(string $url) => Factory::createFiber()
        ->sendRequest(new Request('GET', $url))
        ->getBody()
        ->getContents(),
    2 // 并发数
);
```

## 异常处理

```php
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\Exception\RequestException;

try {
    $response = $client->sendRequest($request);
} catch (NetworkException $e) {
    // 网络错误
    echo '网络错误: ' . $e->getMessage();
    echo '请求 URI: ' . $e->getRequestUri();
} catch (RequestException $e) {
    // 请求格式错误
    echo '请求错误: ' . $e->getMessage();
} catch (\Exception $e) {
    // 其他错误
    echo '错误: ' . $e->getMessage();
}
```

## 项目结构

```
src/
├── Context/
│   └── context.php          # HTTP 上下文辅助类
├── Driver/
│   ├── DriverInterface.php  # 驱动接口
│   ├── CurlDriver.php       # Curl 驱动
│   ├── FiberDriver.php      # Fiber 驱动（PHP 8.1+）
│   ├── SwooleDriver.php     # Swoole 驱动
│   └── AmpDriver.php        # Amp 驱动
├── Exception/
│   ├── HttpException.php    # HTTP 异常基类
│   ├── NetworkException.php # 网络异常
│   └── RequestException.php # 请求异常
├── Middleware/
│   ├── MiddlewareInterface.php   # 中间件接口
│   ├── MiddlewareStack.php       # 中间件栈
│   ├── AuthMiddleware.php        # 认证中间件
│   ├── CacheMiddleware.php       # 缓存中间件
│   ├── RateLimitMiddleware.php   # 限流中间件
│   ├── RetryMiddleware.php       # 重试中间件
│   ├── TimeoutMiddleware.php     # 超时中间件
│   └── LoggingMiddleware.php     # 日志中间件
├── Factory.php              # 客户端工厂
├── HttpClient.php           # HTTP 客户端实现
└── HttpClientInterface.php  # HTTP 客户端接口
```

## 测试

```bash
# 运行所有测试
composer test

# 运行中间件测试
composer test-middlewares

# 生成测试覆盖率报告
composer test-coverage
```

## 要求

- PHP >= 8.1
- ext-curl（CurlDriver 和 FiberDriver 需要）
- kode/context ^2.1
- psr/http-message ^1.0|^2.0
- psr/http-client ^1.0

## 可选依赖

- ext-swoole - Swoole 协程支持
- ext-swow - Swow 协程支持
- amphp/http-client - Amp 异步 HTTP 客户端
- kode/fibers - Fiber 协程池和调度器
- guzzlehttp/psr7 - PSR-7 消息实现

## 许可证

Apache-2.0

## 作者

Kode Team <382601296@qq.com>
