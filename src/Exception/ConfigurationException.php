<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * 配置异常
 *
 * 当客户端配置非法、依赖缺失或驱动不可用时抛出。
 * 继承 \InvalidArgumentException 以便调用方按常规方式捕获。
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class ConfigurationException extends \InvalidArgumentException implements ClientExceptionInterface
{
}
