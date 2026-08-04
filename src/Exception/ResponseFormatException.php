<?php

declare(strict_types=1);

namespace Kode\HttpClient\Exception;

/**
 * 响应格式异常
 *
 * 当响应体无法按预期格式解析（例如 JSON 解码失败）时抛出。
 *
 * @package Kode\HttpClient\Exception
 * @author  Kode Team <382601296@qq.com>
 * @license Apache-2.0
 */
final class ResponseFormatException extends HttpException
{
}
