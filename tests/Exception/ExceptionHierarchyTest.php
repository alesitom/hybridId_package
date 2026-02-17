<?php

declare(strict_types=1);

namespace HybridId\Tests\Exception;

use HybridId\Exception\HybridIdException;
use HybridId\Exception\IdOverflowException;
use HybridId\Exception\InvalidIdException;
use HybridId\Exception\InvalidPrefixException;
use HybridId\Exception\InvalidProfileException;
use HybridId\Exception\NodeRequiredException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Marker interface
    // -------------------------------------------------------------------------

    public function testHybridIdExceptionExtendsThrowable(): void
    {
        $this->assertTrue(is_a(HybridIdException::class, \Throwable::class, true));
    }

    // -------------------------------------------------------------------------
    // Each exception implements the marker interface
    // -------------------------------------------------------------------------

    public function testIdOverflowExceptionImplementsMarker(): void
    {
        $e = new IdOverflowException('test');
        $this->assertInstanceOf(HybridIdException::class, $e);
    }

    public function testInvalidIdExceptionImplementsMarker(): void
    {
        $e = new InvalidIdException('test');
        $this->assertInstanceOf(HybridIdException::class, $e);
    }

    public function testInvalidPrefixExceptionImplementsMarker(): void
    {
        $e = new InvalidPrefixException('test');
        $this->assertInstanceOf(HybridIdException::class, $e);
    }

    public function testInvalidProfileExceptionImplementsMarker(): void
    {
        $e = new InvalidProfileException('test');
        $this->assertInstanceOf(HybridIdException::class, $e);
    }

    public function testNodeRequiredExceptionImplementsMarker(): void
    {
        $e = new NodeRequiredException('test');
        $this->assertInstanceOf(HybridIdException::class, $e);
    }

    // -------------------------------------------------------------------------
    // Each exception extends the correct SPL parent
    // -------------------------------------------------------------------------

    public function testIdOverflowExceptionExtendsSplOverflow(): void
    {
        $e = new IdOverflowException('test');
        $this->assertInstanceOf(\OverflowException::class, $e);
    }

    public function testInvalidIdExceptionExtendsSplInvalidArgument(): void
    {
        $e = new InvalidIdException('test');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testInvalidPrefixExceptionExtendsSplInvalidArgument(): void
    {
        $e = new InvalidPrefixException('test');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testInvalidProfileExceptionExtendsSplInvalidArgument(): void
    {
        $e = new InvalidProfileException('test');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testNodeRequiredExceptionExtendsSplRuntime(): void
    {
        $e = new NodeRequiredException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // -------------------------------------------------------------------------
    // Exceptions are final
    // -------------------------------------------------------------------------

    public function testAllExceptionsAreFinal(): void
    {
        $classes = [
            IdOverflowException::class,
            InvalidIdException::class,
            InvalidPrefixException::class,
            InvalidProfileException::class,
            NodeRequiredException::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                (new \ReflectionClass($class))->isFinal(),
                sprintf('%s should be final', $class),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Catch-all via marker interface
    // -------------------------------------------------------------------------

    public function testCatchAllViaMarkerInterface(): void
    {
        $exceptions = [
            new IdOverflowException('a'),
            new InvalidIdException('b'),
            new InvalidPrefixException('c'),
            new InvalidProfileException('d'),
            new NodeRequiredException('e'),
        ];

        foreach ($exceptions as $e) {
            try {
                throw $e;
            } catch (HybridIdException $caught) {
                $this->assertSame($e->getMessage(), $caught->getMessage());
                continue;
            }

            $this->fail(sprintf('%s was not caught by HybridIdException', $e::class));
        }
    }

    // -------------------------------------------------------------------------
    // Backwards compatibility: SPL catch still works
    // -------------------------------------------------------------------------

    public function testSplCatchStillWorks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        throw new InvalidProfileException('backwards compat');
    }

    public function testOverflowSplCatchStillWorks(): void
    {
        $this->expectException(\OverflowException::class);

        throw new IdOverflowException('backwards compat');
    }

    public function testRuntimeSplCatchStillWorks(): void
    {
        $this->expectException(\RuntimeException::class);

        throw new NodeRequiredException('backwards compat');
    }
}
