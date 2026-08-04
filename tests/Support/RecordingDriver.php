<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests\Support;

use Kode\HttpClient\Driver\ConcurrentDriverInterface;
use Kode\HttpClient\Message\MessageFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 测试用驱动
 *
 * 记录收到的请求并返回预先编排的响应，无需真实网络。
 *
 * @package Kode\HttpClient\Tests\Support
 */
final class RecordingDriver implements ConcurrentDriverInterface
{
    /**
     * 收到的请求
     *
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * 预设的响应队列
     *
     * @var list<ResponseInterface|\Throwable>
     */
    private array $queue;

    /**
     * 是否走过并发通道
     */
    public bool $concurrentUsed = false;

    /**
     * @param list<ResponseInterface|\Throwable> $queue 预设响应，用尽后循环使用最后一项
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $result = array_shift($this->queue) ?? MessageFactory::createResponse(200, [], 'ok');

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * @param array<array-key, RequestInterface> $requests 请求集合
     * @return array<array-key, ResponseInterface|\Throwable> 结果集合
     */
    #[\Override]
    public function sendConcurrent(array $requests): array
    {
        $this->concurrentUsed = true;
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

    /**
     * 获取最后一次收到的请求
     */
    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }
}
