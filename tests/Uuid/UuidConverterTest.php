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
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv8($id);

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv8HasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv8($gen->generate());

        // Version is the 13th hex char (position 14 with hyphen at 8)
        $hex = str_replace('-', '', $uuid);
        $this->assertSame('8', $hex[12]);
    }

    public function testToUUIDv8HasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
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
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv8($id);
        $recovered = UuidConverter::fromUUIDv8($uuid);

        $this->assertSame($id, $recovered);
    }

    public function testUUIDv8RoundTripMultipleIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        for ($i = 0; $i < 50; $i++) {
            $id = $gen->generate();
            $this->assertSame($id, UuidConverter::fromUUIDv8(UuidConverter::toUUIDv8($id)));
        }
    }

    public function testUUIDv8PreservesTimestamp(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
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
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1');
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
        // A valid UUIDv4-format should be rejected by fromUUIDv8
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv4Format($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 8');

        UuidConverter::fromUUIDv8($uuid);
    }

    public function testUUIDv8SortableByTimestamp(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

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
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv7HasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $this->assertSame('7', $hex[12]);
    }

    public function testToUUIDv7HasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $variantNibble = hexdec($hex[16]);
        $this->assertSame(0b10, $variantNibble >> 2);
    }

    public function testUUIDv7PreservesTimestamp(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();
        $originalTs = HybridIdGenerator::extractTimestamp($id);

        $uuid = UuidConverter::toUUIDv7($id);
        $hex = str_replace('-', '', $uuid);
        $uuidTs = hexdec(substr($hex, 0, 12));

        $this->assertSame($originalTs, $uuidTs);
    }

    public function testUUIDv7RoundTripStandard(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
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
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1');

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
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv8($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 7');

        UuidConverter::fromUUIDv7($uuid);
    }

    public function testFromUUIDv7AcceptsProfileEnum(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate();

        $uuid = UuidConverter::toUUIDv7($id);
        $recovered = UuidConverter::fromUUIDv7($uuid, Profile::Standard);

        $this->assertSame($id, $recovered);
    }

    public function testFromUUIDv7RejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $this->expectException(InvalidProfileException::class);

        UuidConverter::fromUUIDv7($uuid, Profile::Extended);
    }

    // =========================================================================
    // UUIDv4-format — lossy
    // =========================================================================

    public function testToUUIDv4FormatReturnsValidFormat(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv4Format($gen->generate());

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $uuid);
    }

    public function testToUUIDv4FormatHasCorrectVersionBit(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv4Format($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $this->assertSame('4', $hex[12]);
    }

    public function testToUUIDv4FormatHasCorrectVariantBits(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv4Format($gen->generate());

        $hex = str_replace('-', '', $uuid);
        $variantNibble = hexdec($hex[16]);
        $this->assertSame(0b10, $variantNibble >> 2);
    }

    public function testToUUIDv4FormatRejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $this->expectException(InvalidProfileException::class);

        UuidConverter::toUUIDv4Format($gen->generate());
    }

    public function testToUUIDv4FormatRejectsInvalidInput(): void
    {
        $this->expectException(InvalidIdException::class);

        UuidConverter::toUUIDv4Format('invalid');
    }

    public function testFromUUIDv4FormatWithExplicitTimestampAndNode(): void
    {
        $uuid = UuidConverter::toUUIDv4Format((new HybridIdGenerator(requireExplicitNode: false))->generate());

        $ts = (int) (microtime(true) * 1000);
        $recovered = UuidConverter::fromUUIDv4Format($uuid, Profile::Standard, $ts, 'A1');

        $this->assertTrue(HybridIdGenerator::isValid($recovered));
        $this->assertSame($ts, HybridIdGenerator::extractTimestamp($recovered));
        $this->assertSame('A1', HybridIdGenerator::extractNode($recovered));
    }

    public function testFromUUIDv4FormatWithNullTimestampUsesCurrentTime(): void
    {
        $uuid = UuidConverter::toUUIDv4Format((new HybridIdGenerator(requireExplicitNode: false))->generate());

        $before = (int) (microtime(true) * 1000);
        $recovered = UuidConverter::fromUUIDv4Format($uuid, 'standard');
        $after = (int) (microtime(true) * 1000);

        $ts = HybridIdGenerator::extractTimestamp($recovered);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testFromUUIDv4FormatRejectsWrongVersion(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $uuid = UuidConverter::toUUIDv7($gen->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('version 4');

        UuidConverter::fromUUIDv4Format($uuid);
    }

    public function testFromUUIDv4FormatRejectsInvalidNodeFormat(): void
    {
        $uuid = UuidConverter::toUUIDv4Format((new HybridIdGenerator(requireExplicitNode: false))->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        UuidConverter::fromUUIDv4Format($uuid, 'standard', null, 'ABC');
    }

    public function testFromUUIDv4FormatRejectsNegativeTimestamp(): void
    {
        $uuid = UuidConverter::toUUIDv4Format((new HybridIdGenerator(requireExplicitNode: false))->generate());

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('non-negative');

        UuidConverter::fromUUIDv4Format($uuid, 'standard', -1);
    }

    public function testFromUUIDv4FormatRejectsOverflowTimestamp(): void
    {
        $uuid = UuidConverter::toUUIDv4Format((new HybridIdGenerator(requireExplicitNode: false))->generate());

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('maximum encodable');

        UuidConverter::fromUUIDv4Format($uuid, 'standard', 62 ** 8);
    }

    public function testFromUUIDv4FormatRejectsExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $uuid = UuidConverter::toUUIDv4Format($gen->generate());

        $this->expectException(InvalidProfileException::class);

        UuidConverter::fromUUIDv4Format($uuid, Profile::Extended);
    }

    // =========================================================================
    // Cross-version
    // =========================================================================

    public function testSameIdProducesDifferentUuids(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();

        $v4 = UuidConverter::toUUIDv4Format($id);
        $v7 = UuidConverter::toUUIDv7($id);
        $v8 = UuidConverter::toUUIDv8($id);

        $this->assertNotSame($v4, $v7);
        $this->assertNotSame($v7, $v8);
        $this->assertNotSame($v4, $v8);
    }

    public function testAllVersionsShareSameTimestampBits(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();

        $v4hex = str_replace('-', '', UuidConverter::toUUIDv4Format($id));
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

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVariantProvider')]
    public function testRejectsInvalidVariantForAllVersions(string $method, string $uuid): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('variant');

        UuidConverter::$method($uuid);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidVariantProvider(): array
    {
        // Variant nibble = 0x0 (00xx instead of 10xx) for each UUID version
        return [
            'v8' => ['fromUUIDv8', '00000000-0000-8000-0000-000000000000'],
            'v7' => ['fromUUIDv7', '00000000-0000-7000-0000-000000000000'],
            'v4' => ['fromUUIDv4Format', '00000000-0000-4000-0000-000000000000'],
        ];
    }

    public function testToUUIDv8RejectsPrefixedId(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('does not accept prefixed IDs');

        UuidConverter::toUUIDv8($id);
    }

    public function testToUUIDv7RejectsPrefixedId(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('ord');

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('does not accept prefixed IDs');

        UuidConverter::toUUIDv7($id);
    }

    public function testToUUIDv4FormatRejectsPrefixedId(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('txn');

        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('does not accept prefixed IDs');

        UuidConverter::toUUIDv4Format($id);
    }
}
