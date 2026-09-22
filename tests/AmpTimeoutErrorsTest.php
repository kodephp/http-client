<?php

declare(strict_types=1);

namespace Kode\HttpClient\Tests;

use Amp\Http\Client\HttpException as AmpHttpException;
use Amp\Http\Client\TimeoutException as AmpTimeoutException;
use Kode\HttpClient\Driver\Internal\AmpTimeoutErrors;
use Kode\HttpClient\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * amphp 超时异常识别测试
 *
 * @package Kode\HttpClient\Tests
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class AmpTimeoutErrorsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(AmpTimeoutException::class)) {
            self::markTestSkipped('需要 amphp/http-client（require-dev）');
        }
    }

    public function testHttpLayerTimeoutIsRecognised(): void
    {
        // 驱动用 setInactivityTimeout/setTransferTimeout 表达超时，amphp 抛的就是这个类
        self::assertTrue(AmpTimeoutErrors::matches(new AmpTimeoutException('inactivity timeout')));
    }

    public function testCoreLevelTimeoutIsRecognised(): void
    {
        self::assertTrue(AmpTimeoutErrors::matches(new \Amp\TimeoutException('timed out')));
    }

    public function testSiblingHttpExceptionIsNotATimeout(): void
    {
        // 超时是 HttpException 的子类；按父类判定会把「服务端口拒绝/协议错误」全标成超时
        self::assertFalse(AmpTimeoutErrors::matches(new AmpHttpException('bad response')));
    }

    public function testUnrelatedThrowableIsNotATimeout(): void
    {
        self::assertFalse(AmpTimeoutErrors::matches(new ConfigurationException('boom')));
        self::assertFalse(AmpTimeoutErrors::matches(new \RuntimeException('boom')));
    }

    /**
     * 驱动侧一旦漏接这个映射，超时就会被降级成 NetworkException；此处钉住被识别的类名清单
     */
    public function testTimeoutClassListHoldsOnlyThrowableClassNames(): void
    {
        $reflected = new \ReflectionClass(AmpTimeoutErrors::class);
        $classes = $reflected->getReflectionConstant('TIMEOUT_CLASSES')->getValue();

        self::assertNotEmpty($classes);

        foreach ($classes as $class) {
            self::assertIsString($class);
            self::assertTrue(
                class_exists($class),
                sprintf('amphp 超时类名不存在（上游已改名或从未存在）: %s', $class)
            );
            self::assertTrue(
                is_a($class, \Throwable::class, true),
                sprintf('%s 不是异常类，instanceof 永远不会命中', $class)
            );
        }
    }
}
