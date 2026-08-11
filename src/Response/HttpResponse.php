<?php

declare(strict_types=1);

namespace Kode\HttpClient\Response;

use Kode\HttpClient\Exception\ResponseFormatException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * 响应装饰器
 *
 * 在保持完整 PSR-7 兼容的前提下，为响应对象补充常用的便捷方法：
 * JSON 解析、状态判断、文本读取等，避免调用方反复书写样板代码。
 *
 * @package Kode\HttpClient\Response
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class HttpResponse implements ResponseInterface
{
    /**
     * 被装饰的原始响应
     */
    private ResponseInterface $response;

    /**
     * 已缓冲的响应体内容
     */
    private ?string $bufferedBody = null;

    /**
     * 构造函数
     *
     * @param ResponseInterface $response 原始 PSR-7 响应
     */
    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    /**
     * 包装一个 PSR-7 响应
     *
     * 若已是 HttpResponse 则原样返回，避免重复包装。
     *
     * @param ResponseInterface $response 原始 PSR-7 响应
     */
    public static function wrap(ResponseInterface $response): self
    {
        return $response instanceof self ? $response : new self($response);
    }

    /**
     * 获取被装饰的原始响应
     */
    public function unwrap(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * 读取响应体文本
     *
     * 内部做了一次缓冲，可重复调用而不受流指针影响。
     */
    public function text(): string
    {
        if ($this->bufferedBody === null) {
            $body = $this->response->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }

            $this->bufferedBody = $body->getContents();
        }

        return $this->bufferedBody;
    }

    /**
     * 将响应体解析为 JSON
     *
     * @param bool $associative true 返回关联数组，false 返回 stdClass
     * @param int $depth 最大解析深度
     * @return mixed 解析结果
     *
     * @throws ResponseFormatException 当响应体不是合法 JSON 时抛出
     */
    public function json(bool $associative = true, int $depth = 512): mixed
    {
        $raw = $this->text();

        if (!json_validate($raw, $depth)) {
            throw new ResponseFormatException(sprintf(
                '响应体不是合法的 JSON：%s',
                json_last_error_msg()
            ));
        }

        return json_decode($raw, $associative, $depth, JSON_THROW_ON_ERROR);
    }

    /**
     * 将响应体解析为关联数组
     *
     * @return array<mixed> 解析结果
     *
     * @throws ResponseFormatException 当响应体不是合法 JSON 对象/数组时抛出
     */
    public function array(): array
    {
        $data = $this->json(true);

        if (!is_array($data)) {
            throw new ResponseFormatException('响应体 JSON 顶层不是对象或数组');
        }

        return $data;
    }

    /**
     * 获取 HTTP 状态码
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * 是否为 2xx 成功响应
     */
    public function successful(): bool
    {
        $status = $this->status();

        return $status >= 200 && $status < 300;
    }

    /**
     * 是否为 200 OK
     */
    public function ok(): bool
    {
        return $this->status() === 200;
    }

    /**
     * 是否为 3xx 重定向响应
     */
    public function redirect(): bool
    {
        $status = $this->status();

        return $status >= 300 && $status < 400;
    }

    /**
     * 是否为 4xx 客户端错误
     */
    public function clientError(): bool
    {
        $status = $this->status();

        return $status >= 400 && $status < 500;
    }

    /**
     * 是否为 5xx 服务端错误
     */
    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * 是否为失败响应（4xx 或 5xx）
     */
    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    /**
     * 获取单个响应头的值
     *
     * @param string $name 响应头名称
     */
    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->response->withStatus($code, $reasonPhrase));
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return new self($this->response->withProtocolVersion($version));
    }

    /**
     * @return array<string, array<int, string>>
     */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    /**
     * @return array<int, string>
     */
    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    #[\Override]
    public function withHeader(string $name, mixed $value): static
    {
        return new self($this->response->withHeader($name, $value));
    }

    #[\Override]
    public function withAddedHeader(string $name, mixed $value): static
    {
        return new self($this->response->withAddedHeader($name, $value));
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return new self($this->response->withoutHeader($name));
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return new self($this->response->withBody($body));
    }

    /**
     * 转换为字符串（等价于读取响应体）
     */
    public function __toString(): string
    {
        return $this->text();
    }
}
