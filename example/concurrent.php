<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kode\HttpClient\Driver\ConcurrentDriverInterface;
use Kode\HttpClient\Exception\NetworkException;
use Kode\HttpClient\HttpClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 并发请求：sendConcurrent / pool 的「全部落定」语义（离线演示）。
 * 单项失败不会中断其余请求，失败项在结果中以 Throwable 呈现，键与入参一一对应。
 *
 * 运行：php example/concurrent.php
 */

/**
 * 桩并发驱动：实现 ConcurrentDriverInterface 才能走真正的并行路径。
 */
final class FlakyConcurrentDriver implements ConcurrentDriverInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (str_contains((string) $request->getUri(), '/fail')) {
            throw new NetworkException('upstream unreachable', $request);
        }

        return new Response(200, [], '{"ok":true}');
    }

    public function sendConcurrent(array $requests): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            try {
                $results[$key] = $this->sendRequest($request);
            } catch (\Throwable $e) {
                $results[$key] = $e;
            }
        }

        return $results;
    }
}

$client = new HttpClient(new FlakyConcurrentDriver());

echo '真正并行可用: ' . var_export($client->supportsParallel(), true) . PHP_EOL;

echo "\n=== sendConcurrent（传入已构建的 PSR-7 请求）===\n";
$requests = [
    'first'  => new Request('GET', 'https://api.example.com/a'),
    'second' => new Request('GET', 'https://api.example.com/fail'),
    'third'  => new Request('GET', 'https://api.example.com/c'),
];

foreach ($client->sendConcurrent($requests) as $key => $result) {
    if ($result instanceof \Throwable) {
        echo "  $key 失败: " . $result->getMessage() . PHP_EOL;
    } else {
        echo "  $key 成功: " . $result->getStatusCode() . ' ' . $result->getBody() . PHP_EOL;
    }
}

echo "\n=== pool（[method, uri, options] 三元组）===\n";
$specs = [
    ['GET', 'https://api.example.com/b', ['query' => ['page' => 1]]],
    ['POST', 'https://api.example.com/c', ['json' => ['x' => 1]]],
    ['GET', 'https://api.example.com/fail', []],
];

foreach ($client->pool($specs) as $i => $result) {
    if ($result instanceof \Throwable) {
        echo "  #$i 失败: " . $result->getMessage() . PHP_EOL;
    } else {
        echo "  #$i 成功: " . $result->getStatusCode() . PHP_EOL;
    }
}

echo "\n提示：启用中间件后 supportsParallel() 会变为 false 并退化为顺序执行。\n";
echo '加一个中间件后: ' . var_export(
    $client->withMiddleware(new \Kode\HttpClient\Middleware\TimeoutMiddleware())->supportsParallel(),
    true
) . PHP_EOL;
