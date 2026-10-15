<?php

// Swow 生态 PSR-7 HTTP 客户端符号桩（phpstan 静态分析用；运行期由 swow/psr7 扩展包提供）

namespace Swow\Psr7\Client;

class Client
{
    public function setTimeout(int $timeout): void {}

    public function connect(string $host, int $port = 0, int $timeout = 0): void {}

    public function enableCrypto(): void {}

    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {}

    public function close(): void {}
}
