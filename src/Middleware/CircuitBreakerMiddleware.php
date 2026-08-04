<?php

declare(strict_types=1);

namespace Kode\HttpClient\Middleware;

use Kode\HttpClient\Exception\CircuitBreakerOpenException;
use Kode\HttpClient\Exception\ConfigurationException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 熔断中间件
 *
 * v2.4 新增。按目标服务（scheme://host:port）独立维护熔断状态，
 * 在下游持续故障时快速失败，避免线程/协程被无效等待拖垮，
 * 并在冷却期后自动进入半开状态试探恢复。
 *
 * 状态机：CLOSED --连续失败达阈值--> OPEN --冷却结束--> HALF_OPEN --连续成功达阈值--> CLOSED
 *
 * @package Kode\HttpClient\Middleware
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class CircuitBreakerMiddleware implements MiddlewareInterface
{
    /**
     * 闭合状态：正常放行
     */
    public const string STATE_CLOSED = 'closed';

    /**
     * 开启状态：快速失败
     */
    public const string STATE_OPEN = 'open';

    /**
     * 半开状态：放行少量试探请求
     */
    public const string STATE_HALF_OPEN = 'half_open';

    /**
     * 默认视为失败的响应状态码
     *
     * @var list<int>
     */
    public const array DEFAULT_FAILURE_STATUS_CODES = [500, 502, 503, 504];

    /**
     * 各作用域的熔断状态
     *
     * @var array<string, array{state: string, failures: int, successes: int, openedAt: float}>
     */
    private array $circuits = [];

    /**
     * 构造函数
     *
     * @param int $failureThreshold 连续失败多少次后开启熔断
     * @param float $resetTimeout 熔断冷却时间（秒）
     * @param int $successThreshold 半开状态下连续成功多少次后恢复
     * @param list<int> $failureStatusCodes 视为失败的响应状态码
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $resetTimeout = 30.0,
        private readonly int $successThreshold = 1,
        private readonly array $failureStatusCodes = self::DEFAULT_FAILURE_STATUS_CODES,
    ) {
        if ($failureThreshold <= 0) {
            throw new ConfigurationException('failureThreshold 必须大于 0');
        }

        if ($successThreshold <= 0) {
            throw new ConfigurationException('successThreshold 必须大于 0');
        }

        if ($resetTimeout <= 0) {
            throw new ConfigurationException('resetTimeout 必须大于 0');
        }
    }

    /**
     * 处理请求
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @param callable $next 下一个处理器
     * @return ResponseInterface PSR-7 响应对象
     *
     * @throws CircuitBreakerOpenException 当熔断器处于开启状态时抛出
     */
    #[\Override]
    public function process(RequestInterface $request, callable $next): ResponseInterface
    {
        $scope = $this->scopeOf($request);
        $circuit = $this->circuits[$scope] ??= [
            'state' => self::STATE_CLOSED,
            'failures' => 0,
            'successes' => 0,
            'openedAt' => 0.0,
        ];

        if ($circuit['state'] === self::STATE_OPEN) {
            $elapsed = microtime(true) - $circuit['openedAt'];

            if ($elapsed < $this->resetTimeout) {
                throw new CircuitBreakerOpenException($scope, $this->resetTimeout - $elapsed);
            }

            $this->circuits[$scope]['state'] = self::STATE_HALF_OPEN;
            $this->circuits[$scope]['successes'] = 0;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            if ($e instanceof NetworkExceptionInterface) {
                $this->recordFailure($scope);
            }

            throw $e;
        }

        if (in_array($response->getStatusCode(), $this->failureStatusCodes, true)) {
            $this->recordFailure($scope);
        } else {
            $this->recordSuccess($scope);
        }

        return $response;
    }

    /**
     * 记录一次失败
     *
     * @param string $scope 熔断作用域
     */
    private function recordFailure(string $scope): void
    {
        $circuit = &$this->circuits[$scope];
        $circuit['failures']++;
        $circuit['successes'] = 0;

        if ($circuit['state'] === self::STATE_HALF_OPEN || $circuit['failures'] >= $this->failureThreshold) {
            $circuit['state'] = self::STATE_OPEN;
            $circuit['openedAt'] = microtime(true);
        }
    }

    /**
     * 记录一次成功
     *
     * @param string $scope 熔断作用域
     */
    private function recordSuccess(string $scope): void
    {
        $circuit = &$this->circuits[$scope];

        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            $circuit['successes']++;

            if ($circuit['successes'] >= $this->successThreshold) {
                $circuit['state'] = self::STATE_CLOSED;
                $circuit['failures'] = 0;
                $circuit['successes'] = 0;
            }

            return;
        }

        $circuit['failures'] = 0;
    }

    /**
     * 计算请求所属的熔断作用域
     *
     * @param RequestInterface $request PSR-7 请求对象
     * @return string 作用域标识
     */
    private function scopeOf(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $scope = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();

        return $port !== null ? $scope . ':' . $port : $scope;
    }

    /**
     * 获取指定作用域的熔断状态
     *
     * @param string $scope 作用域标识
     * @return string 状态值
     */
    public function stateOf(string $scope): string
    {
        return $this->circuits[$scope]['state'] ?? self::STATE_CLOSED;
    }

    /**
     * 获取全部熔断状态快照
     *
     * @return array<string, array{state: string, failures: int, successes: int, openedAt: float}>
     */
    public function snapshot(): array
    {
        return $this->circuits;
    }

    /**
     * 重置熔断状态
     *
     * @param string|null $scope 作用域标识，为 null 时重置全部
     */
    public function reset(?string $scope = null): void
    {
        if ($scope === null) {
            $this->circuits = [];
            return;
        }

        unset($this->circuits[$scope]);
    }
}
