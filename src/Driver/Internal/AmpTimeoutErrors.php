<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver\Internal;

/**
 * amphp 超时异常识别
 *
 * 驱动用 `Request::setInactivityTimeout()/setTransferTimeout()` 表达超时，amphp 为此抛的是
 * 自己的异常类；一律降级成 NetworkException 会让「超时」与「连不上」混为一谈，
 * 调用方按 TimeoutException 分支写的重试/熔断逻辑失效，故集中识别后映射成统一的 TimeoutException。
 *
 * 类名逐条对过 amphp 仓库（amphp/http-client 4.x 与 master、amphp/amp master）：
 * 网上流传的 `Amp\Http\Client\HttpTimeoutException` 并不存在，`Amp\TimeoutCancellation` 是取消令牌而非异常，
 * 两者都不会命中，写了等于没写。
 *
 * 用字符串类名而非 ::class：amphp 未安装时本类同样可安全调用（instanceof 不触发自动加载）。
 *
 * @package Kode\HttpClient\Driver\Internal
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class AmpTimeoutErrors
{
    /**
     * 判定为超时的异常类名
     *
     * @var list<string>
     */
    private const array TIMEOUT_CLASSES = [
        'Amp\\Http\\Client\\TimeoutException',
        'Amp\\TimeoutException',
    ];

    /**
     * 私有构造函数，禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 判断异常是否属于 amphp 的超时异常族
     *
     * @param \Throwable $e 待判异常
     * @return bool 属于超时返回 true
     */
    public static function matches(\Throwable $e): bool
    {
        foreach (self::TIMEOUT_CLASSES as $timeoutClass) {
            if ($e instanceof $timeoutClass) {
                return true;
            }
        }

        return false;
    }
}
