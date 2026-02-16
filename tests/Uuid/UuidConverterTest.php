<?php

declare(strict_types=1);

namespace HybridId\Tests\Uuid;

use HybridId\Exception\InvalidIdException;
use HybridId\Exception\InvalidProfileException;
use HybridId\HybridIdGenerator;
use HybridId\Profile;
use HybridId\Uuid\UuidConverter;
use PHPUnit\Framework\TestCase;

final class UuidConverterTest extends TestCase
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    // =========================================================================
    // UUIDv8 — lossless round-trip
    // =========================================================================

    public function testToUUIDv8ReturnsValidFormat(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv8($id);

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv8HasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv8($gen->generate());

        // Version is the 13th hex char (position 14 with hyphen at 8)
        $hex = str_replace('-', '', $uuid);
        $this->assertSame('8', $hex[12]);
    }

    public function testToUUIDv8HasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv8($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $variantNibble = hexdec($hex[16]);
        $this->assertSame(0b10, $variantNibble >> 2);
    }

    public function testUUIDv8RoundTripCompact(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv8($id);
        $recovered = UuidConverter::fromUUIDv8($uuid);

        $this->assertSame($id, $recovered);
    }

    public function testUUIDv8RoundTripStandard(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv8($id);
        $recovered = UuidConverter::fromUUIDv8($uuid);

        $this->assertSame($id, $recovered);
    }

    public function testUUIDv8RoundTripMultipleIds(): void
    {
        $gen = new HybridIdGenerator();

        for ($i = 0; $i < 50; $i++) {
            $id = $gen->generate();
            $this->assertSame($id, UuidConverter::fromUUIDv8(UuidConverter::toUUIDv8($id)));
        }
    }

    public function testUUIDv8PreservesTimestamp(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();
        $originalTs = HybridIdGenerator::extractTimestamp($id);

        $uuid = UuidConverter::toUUIDv8($id);

        // Extract timestamp from UUID hex (first 12 hex chars = 48 bits)
        $hex = str_replace('-', '', $uuid);
        $uuidTs = hexdec(substr($hex, 0, 12));

        $this->assertSame($originalTs, $uuidTs);
    }

    public function testUUIDv8RejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended');
        $id = $gen->generate();

        $this->expectException(InvalidProfileException::class);

        UuidConverter::toUUIDv8($id);
    }

    public function testUUIDv8RejectsInvalidInput(): void
    {
        $this->expectException(InvalidIdException::class);

        UuidConverter::toUUIDv8('invalid');
    }

    public function testFromUUIDv8RejectsInvalidFormat(): void
    {
        $this->expectException(InvalidIdException::class);

        UuidConverter::fromUUIDv8('not-a-uuid');
    }

    public function testFromUUIDv8RejectsWrongVersion(): void
    {
        // A valid UUIDv4 should be rejected by fromUUIDv8
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv4($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 8');

        UuidConverter::fromUUIDv8($uuid);
    }

    public function testUUIDv8SortableByTimestamp(): void
    {
        $gen = new HybridIdGenerator();

        $id1 = $gen->generate();
        usleep(2000);
        $id2 = $gen->generate();

        $uuid1 = UuidConverter::toUUIDv8($id1);
        $uuid2 = UuidConverter::toUUIDv8($id2);

        // UUIDs should maintain chronological order when sorted as strings
        $this->assertLessThan($uuid2, $uuid1);
    }

    // =========================================================================
    // UUIDv7 — timestamp-preserving
    // =========================================================================

    public function testToUUIDv7ReturnsValidFormat(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv7HasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $this->assertSame('7', $hex[12]);
    }

    public function testToUUIDv7HasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $variantNibble = hexdec($hex[16]);
        $this->assertSame(0b10, $variantNibble >> 2);
    }

    public function testUUIDv7PreservesTimestamp(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();
        $originalTs = HybridIdGenerator::extractTimestamp($id);

        $uuid = UuidConverter::toUUIDv7($id);
        $hex = str_replace('-', '', $uuid);
        $uuidTs = hexdec(substr($hex, 0, 12));

        $this->assertSame($originalTs, $uuidTs);
    }

    public function testUUIDv7RoundTripStandard(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv7($id);
        $recovered = UuidConverter::fromUUIDv7($uuid, Profile::Standard);

        $this->assertSame($id, $recovered);
    }

    public function testUUIDv7RoundTripCompact(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv7($id);
        $recovered = UuidConverter::fromUUIDv7($uuid, 'compact');

        $this->assertSame($id, $recovered);
    }

    public function testUUIDv7RejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended');

        $this->expectException(InvalidProfileException::class);

        UuidConverter::toUUIDv7($gen->generate());
    }

    public function testUUIDv7RejectsInvalidInput(): void
    {
        $this->expectException(InvalidIdException::class);

        UuidConverter::toUUIDv7('invalid');
    }

    public function testFromUUIDv7RejectsWrongVersion(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv8($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 7');

        UuidConverter::fromUUIDv7($uuid);
    }

    public function testFromUUIDv7AcceptsProfileEnum(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv7($id);
        $recovered = UuidConverter::fromUUIDv7($uuid, Profile::Standard);

        $this->assertSame($id, $recovered);
    }

    // =========================================================================
    // UUIDv4 — lossy
    // =========================================================================

    public function testToUUIDv4ReturnsValidFormat(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv4($gen->generate());

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv4HasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv4($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $this->assertSame('4', $hex[12]);
    }

    public function testToUUIDv4HasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv4($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $variantNibble = hexdec($hex[16]);
        $this->assertSame(0b10, $variantNibble >> 2);
    }

    public function testToUUIDv4RejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended');

        $this->expectException(InvalidProfileException::class);

        UuidConverter::toUUIDv4($gen->generate());
    }

    public function testToUUIDv4RejectsInvalidInput(): void
    {
        $this->expectException(InvalidIdException::class);

        UuidConverter::toUUIDv4('invalid');
    }

    public function testFromUUIDv4WithExplicitTimestampAndNode(): void
    {
        $uuid = UuidConverter::toUUIDv4((new HybridIdGenerator())->generate());

        $ts = (int) (microtime(true) * 1000);
        $recovered = UuidConverter::fromUUIDv4($uuid, Profile::Standard, $ts, 'A1');

        $this->assertTrue(HybridIdGenerator::isValid($recovered));
        $this->assertSame($ts, HybridIdGenerator::extractTimestamp($recovered));
        $this->assertSame('A1', HybridIdGenerator::extractNode($recovered));
    }

    public function testFromUUIDv4WithNullTimestampUsesCurrentTime(): void
    {
        $uuid = UuidConverter::toUUIDv4((new HybridIdGenerator())->generate());

        $before = (int) (microtime(true) * 1000);
        $recovered = UuidConverter::fromUUIDv4($uuid, 'standard');
        $after = (int) (microtime(true) * 1000);

        $ts = HybridIdGenerator::extractTimestamp($recovered);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testFromUUIDv4RejectsWrongVersion(): void
    {
        $gen = new HybridIdGenerator();
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 4');

        UuidConverter::fromUUIDv4($uuid);
    }

    public function testFromUUIDv4RejectsInvalidNodeFormat(): void
    {
        $uuid = UuidConverter::toUUIDv4((new HybridIdGenerator())->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        UuidConverter::fromUUIDv4($uuid, 'standard', null, 'ABC');
    }

    // =========================================================================
    // Cross-version
    // =========================================================================

    public function testSameIdProducesDifferentUuids(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $v4 = UuidConverter::toUUIDv4($id);
        $v7 = UuidConverter::toUUIDv7($id);
        $v8 = UuidConverter::toUUIDv8($id);

        $this->assertNotSame($v4, $v7);
        $this->assertNotSame($v7, $v8);
        $this->assertNotSame($v4, $v8);
    }

    public function testAllVersionsShareSameTimestampBits(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $v4hex = str_replace('-', '', UuidConverter::toUUIDv4($id));
        $v7hex = str_replace('-', '', UuidConverter::toUUIDv7($id));
        $v8hex = str_replace('-', '', UuidConverter::toUUIDv8($id));

        // First 12 hex chars are timestamp in all versions
        $this->assertSame(substr($v4hex, 0, 12), substr($v7hex, 0, 12));
        $this->assertSame(substr($v7hex, 0, 12), substr($v8hex, 0, 12));
    }

    // =========================================================================
    // Format validation helpers
    // =========================================================================

    public function testRejectsInvalidUuidFormat(): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Invalid UUID format');

        UuidConverter::fromUUIDv8('not-a-valid-uuid');
    }

    public function testRejectsInvalidVariant(): void
    {
        // Build a UUID with wrong variant (not 10xx)
        // Use a valid-looking hex but with variant nibble = 0x0 (00xx, not 10xx)
        $uuid = '00000000-0000-8000-0000-000000000000';

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('variant');

        UuidConverter::fromUUIDv8($uuid);
    }

    public function testPrefixedIdConvertedCorrectly(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate('usr');

        // toUUIDv8 should parse the prefixed ID and convert the body
        $uuid = UuidConverter::toUUIDv8($id);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);

        // Round-trip recovers the body (unprefixed)
        $recovered = UuidConverter::fromUUIDv8($uuid);
        $this->assertSame(HybridIdGenerator::parse($id)['body'], $recovered);
    }
}
