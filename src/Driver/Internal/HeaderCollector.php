<?php

declare(strict_types=1);

namespace Kode\HttpClient\Driver\Internal;

/**
 * cURL 响应头收集器
 *
 * 作为 CURLOPT_HEADERFUNCTION 的回调使用。
 * 每遇到一个新的状态行就重置一次，因此在开启自动重定向时，
 * 最终保留的始终是「最后一跳」的响应头，避免了把整条重定向链的头混在一起。
 *
 * @package Kode\HttpClient\Driver\Internal
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class HeaderCollector
{
    /**
     * 最终响应状态码
     */
    public int $status = 0;

    /**
     * 最终响应状态短语
     */
    public string $reason = '';

    /**
     * 最终响应协议版本
     */
    public string $version = '1.1';

    /**
     * 最终响应头
     *
     * @var array<string, list<string>>
     */
    public array $headers = [];

    /**
     * 重置收集状态
     */
    public function reset(): void
    {
        $this->status = 0;
        $this->reason = '';
        $this->version = '1.1';
        $this->headers = [];
    }

    /**
     * cURL 头回调
     *
     * @param \CurlHandle $handle cURL 句柄
     * @param string $line 原始响应头行（含换行符）
     * @return int 已消费的字节数（必须等于原始长度，否则 cURL 视为出错）
     */
    public function __invoke(\CurlHandle $handle, string $line): int
    {
        $length = strlen($line);
        $trimmed = trim($line);

        if ($trimmed === '') {
            return $length;
        }

        if (preg_match('#^HTTP/(\d(?:\.\d)?)\s+(\d{3})\s*(.*)$#i', $trimmed, $matches) === 1) {
            $this->version = $matches[1];
            $this->status = (int) $matches[2];
            $this->reason = $matches[3];
            $this->headers = [];

            return $length;
        }

        $position = strpos($trimmed, ':');
        if ($position === false) {
            return $length;
        }

        $name = trim(substr($trimmed, 0, $position));
        $value = trim(substr($trimmed, $position + 1));

        if ($name === '') {
            return $length;
        }

        $this->headers[$name][] = $value;

        return $length;
    }
}
