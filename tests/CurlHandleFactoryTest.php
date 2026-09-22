<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use Kode\HttpClient\Config\TransportOptions;
use Kode\HttpClient\Driver\Internal\CurlHandleFactory;
use Kode\HttpClient\Driver\Internal\HeaderCollector;
use Kode\HttpClient\Request\RequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * cURL 选项翻译测试
 *
 * 覆盖「HTTP 方法与 TLS/超时配置如何映射成 cURL 选项」这条最容易静默跑偏的分支：
 * cURL 没有 setopt 反查能力，句柄一旦建立就断言不到，因此直接测翻译出的选项数组。
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CurlHandleFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('需要 ext-curl');
        }
    }

    /**
     * 取某次请求翻译出的 cURL 选项
     *
     * @param array<string, mixed> $options 请求选项
     * @return array<int, mixed>
     */
    private function optionsFor(
        string $method,
        array $options = [],
        ?TransportOptions $transport = null,
        string $userAgentSuffix = ''
    ): array {
        return CurlHandleFactory::buildOptions(
            RequestBuilder::build($method, 'https://example.com/x', $options),
            $transport ?? new TransportOptions(),
            new HeaderCollector(),
            $userAgentSuffix
        );
    }

    public function testHeadUsesNobodyInsteadOfCustomRequest(): void
    {
        $options = $this->optionsFor('HEAD');

        self::assertTrue($options[CURLOPT_NOBODY] ?? false, 'HEAD 必须走 NOBODY，否则 cURL 会等待并不存在的响应体');
        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
    }

    public function testPostIsNotPinnedByCustomRequest(): void
    {
        $options = $this->optionsFor('POST', ['body' => 'a=1']);

        self::assertTrue($options[CURLOPT_POST] ?? false);
        self::assertSame('a=1', $options[CURLOPT_POSTFIELDS] ?? null);
        // 钉住方法会让 301/302 之后仍以 POST 重放到重定向目标（副作用跑两遍）
        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
    }

    public function testGetWithoutBodyCarriesNoMethodOverride(): void
    {
        $options = $this->optionsFor('GET');

        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
        self::assertArrayNotHasKey(CURLOPT_POST, $options);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
    }

    public function testGetWithBodyStillReportsGet(): void
    {
        $options = $this->optionsFor('GET', ['body' => 'q=1']);

        // 带体的 GET 只设 POSTFIELDS 会被 cURL 自己改成 POST
        self::assertSame('GET', $options[CURLOPT_CUSTOMREQUEST] ?? null);
        self::assertSame('q=1', $options[CURLOPT_POSTFIELDS] ?? null);
    }

    public function testOtherMethodsUseCustomRequest(): void
    {
        self::assertSame('PUT', $this->optionsFor('put')[CURLOPT_CUSTOMREQUEST] ?? null);
        self::assertSame('PATCH', $this->optionsFor('PATCH')[CURLOPT_CUSTOMREQUEST] ?? null);
        self::assertSame('DELETE', $this->optionsFor('DELETE')[CURLOPT_CUSTOMREQUEST] ?? null);
    }

    public function testTimeoutOfZeroIsNotSentToCurl(): void
    {
        self::assertArrayNotHasKey(CURLOPT_TIMEOUT_MS, $this->optionsFor('GET', [], new TransportOptions(timeout: 0.0)));
        self::assertSame(
            500,
            $this->optionsFor('GET', [], new TransportOptions(timeout: 0.5))[CURLOPT_TIMEOUT_MS] ?? null
        );
    }

    public function testTlsVerificationDefaultsToOn(): void
    {
        $options = $this->optionsFor('GET');

        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER] ?? false);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST] ?? null);
    }

    public function testVerifyFalseDisablesTlsChecks(): void
    {
        $options = $this->optionsFor('GET', [], new TransportOptions(verify: false));

        self::assertFalse($options[CURLOPT_SSL_VERIFYPEER] ?? true);
        self::assertSame(0, $options[CURLOPT_SSL_VERIFYHOST] ?? null);
    }

    public function testCaBundlePathIsForwarded(): void
    {
        $dir = sys_get_temp_dir();
        $options = $this->optionsFor('GET', [], new TransportOptions(verify: $dir));

        self::assertSame($dir, $options[CURLOPT_CAPATH] ?? null, '目录走 CAPATH');
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $options);

        $file = __FILE__;
        self::assertSame(
            $file,
            $this->optionsFor('GET', [], new TransportOptions(verify: $file))[CURLOPT_CAINFO] ?? null,
            '文件走 CAINFO'
        );
    }

    public function testCallerCurlOptionsWinOverDerivedOnes(): void
    {
        $options = $this->optionsFor(
            'GET',
            [],
            new TransportOptions(followRedirects: true, curlOptions: [CURLOPT_FOLLOWLOCATION => false])
        );

        self::assertFalse($options[CURLOPT_FOLLOWLOCATION] ?? true);
    }

    public function testHeadersCarryRequestValuesOverDefaultsAndUserAgentSuffix(): void
    {
        $options = $this->optionsFor(
            'GET',
            ['headers' => ['X-Trace' => 'abc']],
            new TransportOptions(
                userAgent: 'kode-test',
                defaultHeaders: ['X-Trace' => 'ignored', 'Accept' => 'text/plain']
            ),
            'curl'
        );

        $headers = $options[CURLOPT_HTTPHEADER] ?? [];

        self::assertContains('X-Trace: abc', $headers);
        // 请求自带同名头时默认值不得再出一条（重复头 = 服务端按未知规则取一个）
        self::assertNotContains('X-Trace: ignored', $headers);
        self::assertContains('Accept: text/plain', $headers);
        self::assertContains('User-Agent: kode-test (curl)', $headers);
    }
}
