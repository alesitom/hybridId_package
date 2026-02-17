<?php

declare(strict_types=1);

namespace HybridId\Uuid;

use HybridId\Exception\IdOverflowException;
use HybridId\Exception\InvalidIdException;
use HybridId\Exception\InvalidProfileException;
use HybridId\HybridIdGenerator;
use HybridId\Profile;

/**
 * @warning Prefixes are NOT preserved through UUID conversion.
 *          Methods toUUIDv8/v7/v4 reject prefixed IDs to prevent silent
 *          prefix loss that could lead to type confusion. Strip the prefix
 *          explicitly before converting and track it separately.
 */
final class UuidConverter
{
    private function __construct() {}

    // -------------------------------------------------------------------------
    // UUIDv8 (RFC 9562 — lossless for compact/standard)
    // -------------------------------------------------------------------------

    public static function toUUIDv8(string $hybridId): string
    {
        self::rejectPrefixed($hybridId, 'toUUIDv8');

        $parsed = HybridIdGenerator::parse($hybridId);
        if (!$parsed['valid']) {
            throw new InvalidIdException('Invalid HybridId: cannot convert to UUIDv8');
        }

        $profileIndex = match ($parsed['profile']) {
            'compact' => 0,
            'standard' => 1,
            default => throw new InvalidProfileException(
                sprintf('Profile "%s" cannot be losslessly packed into UUIDv8 (max 60 random bits)', $parsed['profile']),
            ),
        };

        $timestamp = $parsed['timestamp'];
        $nodeValue = $parsed['node'] !== null ? HybridIdGenerator::decodeBase62($parsed['node']) : 0;
        $randomValue = HybridIdGenerator::decodeBase62($parsed['random']);

        // custom_c: [2-bit profile index][60-bit random]
        $customC = ($profileIndex << 60) | ($randomValue & 0x0FFFFFFFFFFFFFFF);

        // Build 32-char hex string
        $hex = sprintf('%012x', $timestamp);                              // custom_a: 48 bits
        $hex .= '8';                                                       // version: 1000
        $hex .= sprintf('%03x', $nodeValue);                               // custom_b: 12 bits

        $variantAndHigh = (0b10 << 2) | (($customC >> 60) & 0x3);
        $hex .= sprintf('%x', $variantAndHigh);                            // variant(2) + high(2)

        $hex .= sprintf('%015x', $customC & 0x0FFFFFFFFFFFFFFF);           // low 60 bits

        return self::insertHyphens($hex);
    }

    public static function fromUUIDv8(string $uuid): string
    {
        self::assertUuidFormat($uuid, 8);

        $hex = self::stripHyphens($uuid);

        $timestamp = self::safeHexdec(substr($hex, 0, 12));
        $nodeValue = self::safeHexdec(substr($hex, 13, 3));

        // Reconstruct custom_c (62 bits)
        $high2 = hexdec(substr($hex, 16, 1)) & 0x3;
        $low60 = self::safeHexdec(substr($hex, 17, 15));
        $customC = ($high2 << 60) | $low60;

        $profileIndex = ($customC >> 60) & 0x3;
        $randomValue = $customC & 0x0FFFFFFFFFFFFFFF;

        $profile = match ($profileIndex) {
            0 => 'compact',
            1 => 'standard',
            default => throw new InvalidIdException('Unrecognized profile index in UUIDv8'),
        };

        $config = HybridIdGenerator::profileConfig($profile);

        $tsChars = HybridIdGenerator::encodeBase62($timestamp, 8);
        $randomChars = HybridIdGenerator::encodeBase62($randomValue, $config['random']);

        if ($config['node'] > 0) {
            $nodeChars = HybridIdGenerator::encodeBase62($nodeValue, $config['node']);

            return $tsChars . $nodeChars . $randomChars;
        }

        return $tsChars . $randomChars;
    }

    // -------------------------------------------------------------------------
    // UUIDv7 (timestamp-preserving)
    // -------------------------------------------------------------------------

    public static function toUUIDv7(string $hybridId): string
    {
        self::rejectPrefixed($hybridId, 'toUUIDv7');

        $parsed = HybridIdGenerator::parse($hybridId);
        if (!$parsed['valid']) {
            throw new InvalidIdException('Invalid HybridId: cannot convert to UUIDv7');
        }

        self::assertSupportedProfile($parsed['profile'], 'toUUIDv7');

        $timestamp = $parsed['timestamp'];
        $nodeValue = $parsed['node'] !== null ? HybridIdGenerator::decodeBase62($parsed['node']) : 0;
        $randomValue = HybridIdGenerator::decodeBase62($parsed['random']);

        $hex = sprintf('%012x', $timestamp);
        $hex .= '7';
        $hex .= sprintf('%03x', $nodeValue);

        $variantAndHigh = (0b10 << 2) | (($randomValue >> 58) & 0x3);
        $hex .= sprintf('%x', $variantAndHigh);

        $hex .= sprintf('%015x', $randomValue & 0x03FFFFFFFFFFFFFF);

        return self::insertHyphens($hex);
    }

    public static function fromUUIDv7(string $uuid, Profile|string $profile = Profile::Standard): string
    {
        self::assertUuidFormat($uuid, 7);

        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $hex = self::stripHyphens($uuid);

        $timestamp = self::safeHexdec(substr($hex, 0, 12));
        $nodeValue = self::safeHexdec(substr($hex, 13, 3));

        $high2 = hexdec(substr($hex, 16, 1)) & 0x3;
        $low58 = self::safeHexdec(substr($hex, 17, 15));
        $randomValue = ($high2 << 58) | $low58;

        $config = HybridIdGenerator::profileConfig($profileName);

        $tsChars = HybridIdGenerator::encodeBase62($timestamp, 8);
        $randomChars = HybridIdGenerator::encodeBase62($randomValue, $config['random']);

        if ($config['node'] > 0) {
            $nodeChars = HybridIdGenerator::encodeBase62($nodeValue, $config['node']);

            return $tsChars . $nodeChars . $randomChars;
        }

        return $tsChars . $randomChars;
    }

    // -------------------------------------------------------------------------
    // UUIDv4-format (lossy both directions)
    //
    // WARNING: These methods produce/consume UUIDs with v4 structure
    // (version=4, variant=10xx) but the content is deterministically derived
    // from HybridId data — NOT 122 bits of cryptographic randomness as
    // required by RFC 9562 §5.4. Do NOT use the output where a true UUIDv4
    // is expected. For standards-compliant conversions use toUUIDv8()
    // (lossless) or toUUIDv7() (timestamp-preserving).
    // -------------------------------------------------------------------------

    /**
     * Convert a HybridId to a UUID with v4 formatting (version=4, variant=10xx).
     *
     * The output is NOT a true random UUIDv4 per RFC 9562 — it deterministically
     * encodes HybridId data into UUID v4 structure. Conversion is lossy: the
     * original HybridId cannot be fully recovered without supplying the timestamp
     * and node externally via fromUUIDv4Format().
     *
     * For standards-compliant conversions, prefer toUUIDv8() (lossless) or
     * toUUIDv7() (timestamp-preserving).
     */
    public static function toUUIDv4Format(string $hybridId): string
    {
        self::rejectPrefixed($hybridId, 'toUUIDv4Format');

        $parsed = HybridIdGenerator::parse($hybridId);
        if (!$parsed['valid']) {
            throw new InvalidIdException('Invalid HybridId: cannot convert to UUIDv4 format');
        }

        self::assertSupportedProfile($parsed['profile'], 'toUUIDv4Format');

        $timestamp = $parsed['timestamp'];
        $nodeValue = $parsed['node'] !== null ? HybridIdGenerator::decodeBase62($parsed['node']) : 0;
        $randomValue = HybridIdGenerator::decodeBase62($parsed['random']);

        $hex = sprintf('%012x', $timestamp);
        $hex .= '4';
        $hex .= sprintf('%03x', $nodeValue);

        $variantAndHigh = (0b10 << 2) | (($randomValue >> 58) & 0x3);
        $hex .= sprintf('%x', $variantAndHigh);

        $hex .= sprintf('%015x', $randomValue & 0x03FFFFFFFFFFFFFF);

        return self::insertHyphens($hex);
    }

    /**
     * Reconstruct a HybridId from a UUID previously created with toUUIDv4Format().
     *
     * Because v4-format conversion is lossy, you must supply the original timestamp
     * and node externally. When $timestampMs is null, current time is used.
     */
    public static function fromUUIDv4Format(
        string $uuid,
        Profile|string $profile = Profile::Standard,
        ?int $timestampMs = null,
        ?string $node = null,
    ): string {
        self::assertUuidFormat($uuid, 4);

        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $hex = self::stripHyphens($uuid);
        $config = HybridIdGenerator::profileConfig($profileName);

        $timestamp = $timestampMs ?? (int) (microtime(true) * 1000);

        if ($timestamp < 0) {
            throw new InvalidIdException('Timestamp must be non-negative');
        }
        if ($timestamp > 62 ** 8 - 1) {
            throw new IdOverflowException('Timestamp exceeds maximum encodable value (62^8 - 1)');
        }

        if ($node !== null) {
            if (strlen($node) !== 2 || strspn($node, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') !== 2) {
                throw new InvalidIdException('Node must be exactly 2 base62 characters');
            }
            $nodeChars = $node;
        } else {
            $nodeValue = self::safeHexdec(substr($hex, 13, 3));
            $nodeChars = HybridIdGenerator::encodeBase62($nodeValue, 2);
        }

        $high2 = hexdec(substr($hex, 16, 1)) & 0x3;
        $low58 = self::safeHexdec(substr($hex, 17, 15));
        $randomValue = ($high2 << 58) | $low58;

        $tsChars = HybridIdGenerator::encodeBase62($timestamp, 8);
        $randomChars = HybridIdGenerator::encodeBase62($randomValue, $config['random']);

        if ($config['node'] > 0) {
            return $tsChars . $nodeChars . $randomChars;
        }

        return $tsChars . $randomChars;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function assertUuidFormat(string $uuid, int $expectedVersion): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (preg_match($pattern, $uuid) !== 1) {
            throw new InvalidIdException('Invalid UUID format');
        }

        $hex = self::stripHyphens($uuid);

        $version = hexdec($hex[12]);
        if ($version !== $expectedVersion) {
            throw new InvalidIdException(
                sprintf('Expected UUID version %d, got %d', $expectedVersion, $version),
            );
        }

        $variantNibble = hexdec($hex[16]);
        if (($variantNibble >> 2) !== 0b10) {
            throw new InvalidIdException('Invalid UUID variant: expected RFC 4122 variant (10xx)');
        }
    }

    private static function assertSupportedProfile(string $profile, string $method): void
    {
        if ($profile !== 'compact' && $profile !== 'standard') {
            throw new InvalidProfileException(
                sprintf(
                    '%s() only supports compact and standard profiles (got "%s")',
                    $method,
                    $profile,
                ),
            );
        }
    }

    private static function insertHyphens(string $hex32): string
    {
        return substr($hex32, 0, 8) . '-'
            . substr($hex32, 8, 4) . '-'
            . substr($hex32, 12, 4) . '-'
            . substr($hex32, 16, 4) . '-'
            . substr($hex32, 20, 12);
    }

    private static function stripHyphens(string $uuid): string
    {
        return strtolower(str_replace('-', '', $uuid));
    }

    private static function rejectPrefixed(string $hybridId, string $method): void
    {
        if (HybridIdGenerator::extractPrefix($hybridId) !== null) {
            throw new InvalidIdException(
                sprintf(
                    '%s() does not accept prefixed IDs — prefixes are lost during UUID conversion. '
                    . 'Strip the prefix first with HybridIdGenerator::extractPrefix() and track it separately.',
                    $method,
                ),
            );
        }
    }

    private static function safeHexdec(string $hex): int
    {
        $result = hexdec($hex);
        if (is_float($result)) {
            throw new InvalidIdException('Hex value exceeds 64-bit integer range');
        }
        return $result;
    }
}
