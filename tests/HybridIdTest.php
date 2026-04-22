<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\Exception\InvalidIdException;
use HybridId\HybridId;
use HybridId\HybridIdGenerator;
use PHPUnit\Framework\TestCase;

final class HybridIdTest extends TestCase
{
    public function testValidIdIsParsedCorrectly(): void
    {
        $gen = new HybridIdGenerator(node: 'T1');
        $raw = $gen->generate('usr');

        $id = new HybridId($raw);

        $this->assertSame($raw, $id->toString());
        $this->assertSame($raw, (string) $id);
        $this->assertSame('"' . $raw . '"', json_encode($id));
        
        $this->assertSame('usr', $id->getPrefix());
        $this->assertSame('standard', $id->getProfile());
        $this->assertInstanceOf(\DateTimeImmutable::class, $id->getDateTime());
        $this->assertSame('T1', $id->getNode());
    }

    public function testFromStringFactory(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');
        $raw = $gen->generate();

        $id = HybridId::fromString($raw);

        $this->assertNull($id->getPrefix());
        $this->assertSame('compact', $id->getProfile());
        $this->assertNull($id->getNode());
    }

    public function testInvalidIdThrowsException(): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Invalid HybridId format');

        new HybridId('invalid');
    }
}
