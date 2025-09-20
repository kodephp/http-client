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
use Kode\Context\Context;
use GuzzleHttp\Psr7\Request;

// 创建客户端
$client = Factory::create();

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 创建上下文
$context = new Context();
$context = $context->withTimeout(5.0); // 5秒超时

// 发送请求
$response = $client->sendRequest($request, $context);
```

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
    }
]);

// 创建请求
$request = new Request('GET', 'https://httpbin.org/get');

// 发送请求（将自动应用超时、重试和日志中间件）
$response = $client->sendRequest($request);
```

## 驱动支持

| 运行环境 | 推荐驱动 | 说明 |
|---------|----------|------|
| Swoole 启用 | `SwooleDriver` | 高性能、原生协程支持 |
| Amp 可用 | `AmpDriver` | 基于事件循环的纯 PHP 实现 |
| 默认环境 | `CurlDriver` | 基于 curl 扩展的同步实现 |

## 许可证

Apache-2.0