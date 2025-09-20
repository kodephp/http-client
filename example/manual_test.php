<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\Context\Context;

// 手动测试中间件功能
echo "Testing middleware functionality...\n";

// 测试日志中间件
$logs = [];
$logger = function (string $message) use (&$logs) {
    $logs[] = $message;
    echo "LOG: " . $message . "\n";
};

// 模拟日志中间件
class LoggingMiddleware {
    private $logger;
    
    public function __construct(callable $logger) {
        $this->logger = $logger;
    }
    
    public function process(Request $request, Context $context, callable $next): Response {
        $startTime = microtime(true);
        
        $requestLog = sprintf(
            'HTTP Request: %s %s',
            $request->getMethod(),
            $request->getUri()
        );
        
        ($this->logger)($requestLog);

        try {
            $response = $next($request, $context);
            
            $duration = (microtime(true) - $startTime) * 1000;
            $responseLog = sprintf(
                'HTTP Response: %d %s (%.2f ms)',
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $duration
            );
            
            ($this->logger)($responseLog);
            
            return $response;
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $errorLog = sprintf(
                'HTTP Error: %s (%.2f ms)',
                $e->getMessage(),
                $duration
            );
            
            ($this->logger)($errorLog);
            
            throw $e;
        }
    }
}

// 测试超时中间件
class TimeoutMiddleware {
    private float $defaultTimeout;
    
    public function __construct(float $defaultTimeout = 30.0) {
        $this->defaultTimeout = $defaultTimeout;
    }
    
    public function process(Request $request, Context $context, callable $next): Response {
        $timeout = $context->getTimeout() ?? $this->defaultTimeout;
        $newContext = $context->withTimeout($timeout);
        return $next($request, $newContext);
    }
}

// 测试重试中间件
class RetryMiddleware {
    private int $maxRetries;
    
    public function __construct(int $maxRetries = 3) {
        $this->maxRetries = $maxRetries;
    }
    
    public function process(Request $request, Context $context, callable $next): Response {
        $attempt = 0;
        
        while (true) {
            try {
                return $next($request, $context);
            } catch (\Exception $e) {
                $attempt++;
                
                if ($attempt > $this->maxRetries) {
                    throw $e;
                }
                
                // 简单的退避策略
                $delay = pow(2, $attempt - 1) * 100000; // 微秒
                usleep($delay);
            }
        }
    }
}

// 创建测试请求
$request = new Request('GET', 'https://httpbin.org/get');
$context = new Context();

// 创建中间件
$loggingMiddleware = new LoggingMiddleware($logger);
$timeoutMiddleware = new TimeoutMiddleware(5.0);
$retryMiddleware = new RetryMiddleware(3);

// 创建处理链
$handler = function (Request $request, Context $context) {
    return new Response(200, [], 'Hello World');
};

// 应用中间件
$handler = function (Request $request, Context $context) use ($retryMiddleware, $handler) {
    return $retryMiddleware->process($request, $context, $handler);
};

$handler = function (Request $request, Context $context) use ($timeoutMiddleware, $handler) {
    return $timeoutMiddleware->process($request, $context, $handler);
};

$handler = function (Request $request, Context $context) use ($loggingMiddleware, $handler) {
    return $loggingMiddleware->process($request, $context, $handler);
};

// 执行请求
try {
    $response = $handler($request, $context);
    echo "Response Status: " . $response->getStatusCode() . "\n";
    echo "Response Body: " . (string) $response->getBody() . "\n";
} catch (\Exception $e) {
    echo "Request failed: " . $e->getMessage() . "\n";
}

echo "Test completed.\n";