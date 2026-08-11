<?php

declare(strict_types=1);

namespace Kode\HttpClient\Config;

use Kode\HttpClient\Exception\ConfigurationException;

/**
 * 传输层配置
 *
 * 描述驱动执行一次 HTTP 请求时的底层行为（超时、重定向、TLS、代理等）。
 * 不可变对象，所有派生操作均返回新实例。
 *
 * @package Kode\HttpClient\Config
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final readonly class TransportOptions
{
    /**
     * 默认 User-Agent
     */
    public const string DEFAULT_USER_AGENT = 'KodeHttpClient/2.4';

    /**
     * 构造函数
     *
     * @param float $timeout 总超时时间（秒），0 表示不限制
     * @param float $connectTimeout 连接超时时间（秒）
     * @param bool $followRedirects 是否自动跟随重定向
     * @param int $maxRedirects 最大重定向次数
     * @param bool|string $verify TLS 校验：true 使用系统 CA，false 关闭校验，字符串为 CA 证书路径
     * @param string|null $proxy 代理地址，例如 http://127.0.0.1:7890
     * @param bool $decodeContent 是否自动解压 gzip/deflate/br 响应体
     * @param string $userAgent 默认 User-Agent
     * @param array<string, string> $defaultHeaders 默认请求头（请求自带同名头时不覆盖）
     * @param array<int, mixed> $curlOptions 透传给 curl_setopt 的额外选项
     */
    public function __construct(
        public float $timeout = 30.0,
        public float $connectTimeout = 10.0,
        public bool $followRedirects = true,
        public int $maxRedirects = 5,
        public bool|string $verify = true,
        public ?string $proxy = null,
        public bool $decodeContent = true,
        public string $userAgent = self::DEFAULT_USER_AGENT,
        public array $defaultHeaders = [],
        public array $curlOptions = [],
    ) {
        if ($this->timeout < 0) {
            throw new ConfigurationException('timeout 不能为负数');
        }
        if ($this->connectTimeout < 0) {
            throw new ConfigurationException('connect_timeout 不能为负数');
        }
        if ($this->maxRedirects < 0) {
            throw new ConfigurationException('max_redirects 不能为负数');
        }
    }

    /**
     * 从配置数组构建
     *
     * 支持 snake_case 与 camelCase 两种键名写法。
     *
     * @param array<string, mixed> $options 配置数组
     */
    public static function fromArray(array $options): self
    {
        $pick = static function (array $source, string ...$keys): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source)) {
                    return $source[$key];
                }
            }
            return null;
        };

        $verify = $pick($options, 'verify', 'ssl_verify');
        $default = new self();

        return new self(
            timeout: (float) ($pick($options, 'timeout') ?? $default->timeout),
            connectTimeout: (float) ($pick($options, 'connect_timeout', 'connectTimeout') ?? $default->connectTimeout),
            followRedirects: (bool) ($pick($options, 'follow_redirects', 'followRedirects') ?? $default->followRedirects),
            maxRedirects: (int) ($pick($options, 'max_redirects', 'maxRedirects') ?? $default->maxRedirects),
            verify: is_string($verify) || is_bool($verify) ? $verify : $default->verify,
            proxy: ($p = $pick($options, 'proxy')) !== null ? (string) $p : null,
            decodeContent: (bool) ($pick($options, 'decode_content', 'decodeContent') ?? $default->decodeContent),
            userAgent: (string) ($pick($options, 'user_agent', 'userAgent') ?? $default->userAgent),
            defaultHeaders: (array) ($pick($options, 'default_headers', 'defaultHeaders') ?? $default->defaultHeaders),
            curlOptions: (array) ($pick($options, 'curl_options', 'curlOptions') ?? $default->curlOptions),
        );
    }

    /**
     * 派生一个覆盖了部分字段的新实例
     *
     * @param array<string, mixed> $overrides 需要覆盖的字段
     */
    public function with(array $overrides): self
    {
        return self::fromArray($overrides + $this->toArray());
    }

    /**
     * 导出为配置数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'follow_redirects' => $this->followRedirects,
            'max_redirects' => $this->maxRedirects,
            'verify' => $this->verify,
            'proxy' => $this->proxy,
            'decode_content' => $this->decodeContent,
            'user_agent' => $this->userAgent,
            'default_headers' => $this->defaultHeaders,
            'curl_options' => $this->curlOptions,
        ];
    }
}
