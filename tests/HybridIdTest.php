<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\Exception\InvalidIdException;
use HybridId\HybridId;
use HybridId\HybridIdGenerator;
use HybridId\ProfileRegistry;
use PHPUnit\Framework\TestCase;

final class HybridIdTest extends TestCase
{
    public function testValidIdIsParsedCorrectly(): void
    {
        $gen = new HybridIdGenerator(node: 'T1');
        $raw = $gen->generate('usr');

        $id = new HybridId($raw);

        $this->assertSame($raw, $id->id);
        $this->assertSame($raw, (string) $id);
        $this->assertSame('"' . $raw . '"', json_encode($id));

        $this->assertSame('usr', $id->prefix);
        $this->assertSame('standard', $id->profile);
        $this->assertInstanceOf(\DateTimeImmutable::class, $id->dateTime);
        $this->assertSame('T1', $id->node);
    }

    public function testFromStringFactory(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');
        $raw = $gen->generate();

        $id = HybridId::fromString($raw);

        $this->assertNull($id->prefix);
        $this->assertSame('compact', $id->profile);
        $this->assertNull($id->node);
    }

    public function testExtendedProfileIsParsed(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'Ex');
        $raw = $gen->generate('log');

        $id = new HybridId($raw);

        $this->assertSame('log', $id->prefix);
        $this->assertSame('extended', $id->profile);
        $this->assertSame('Ex', $id->node);
    }

    public function testTimestampRoundTrip(): void
    {
        $gen = new HybridIdGenerator(node: 'R1');
        $beforeMs = (int) (microtime(true) * 1000);
        $raw = $gen->generate();
        $afterMs = (int) (microtime(true) * 1000);

        $id = new HybridId($raw);

        $this->assertGreaterThanOrEqual($beforeMs, $id->timestamp);
        $this->assertLessThanOrEqual($afterMs + 1, $id->timestamp);
        $this->assertSame($id->timestamp, (int) $id->dateTime->format('Uv'));
    }

    public function testGeneratorWithCustomNodelessProfileRoundTrip(): void
    {
        // Round-trip: custom nodeless profile (node=0) generates a valid ID and
        // the generator reports null for getNode(). Parsing via the VO is not
        // applicable: static parse() uses the default registry and has no
        // knowledge of caller-registered profiles.
        $registry = ProfileRegistry::withDefaults();
        $registry->register('tiny', 10, 0);

        $gen = new HybridIdGenerator(profile: 'tiny', registry: $registry);
        $raw = $gen->generate();

        $this->assertSame(18, strlen($raw));
        $this->assertNull($gen->getNode());
        $this->assertSame('tiny', $gen->getProfile());
    }

    public function testInvalidIdThrowsException(): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Invalid HybridId format');

        new HybridId('invalid');
    }
}
