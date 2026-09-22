<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver\Internal;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Context\Context;

/**
 * 解析本次请求实际生效的传输配置（各驱动共用）
 *
 * 使用方需声明 `private readonly ?TransportOptions $defaults`。
 *
 * 三级优先级：请求级按字段覆盖（Context::transportOverrides()，含 timeout）
 * > 驱动级默认配置 > 上下文整体配置（无驱动默认配置时的回退）。
 *
 * @package Kode\HttpClient\Driver\Internal
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
trait ResolvesTransportOptions
{
    /**
     * 解析本次请求实际生效的传输配置
     */
    private function resolveOptions(): TransportOptions
    {
        $overrides = Context::transportOverrides();
        $timeout = Context::getTimeout();

        if ($timeout !== null) {
            $overrides['timeout'] = $timeout;
        }

        $base = $this->defaults ?? Context::getTransportOptions();

        return $overrides === [] ? $base : $base->with($overrides);
    }
}
