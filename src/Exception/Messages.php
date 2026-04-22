<?php

declare(strict_types=1);

namespace HybridId\Exception;

/**
 * Centralized exception messages.
 *
 * @internal
 */
final class Messages
{
    // ProfileRegistry
    public const PROFILE_NAME_INVALID = 'Profile name must be lowercase alphanumeric, starting with a letter';
    public const PROFILE_EXISTS = 'Profile "%s" already exists';
    public const RANDOM_LENGTH_INVALID = 'Random length must be between 6 and 128';
    public const LENGTH_CONFLICT = 'Length %d conflicts with existing profile "%s"';

    // MockHybridIdGenerator
    public const MOCK_EMPTY = 'MockHybridIdGenerator requires at least one ID';
    public const MOCK_PREFIX_MISMATCH = 'MockHybridIdGenerator: generate() called with prefix "%s" but ID "%s" does not start with "%s_". %s';
    public const MOCK_BATCH_LIMIT = 'Batch count must be between 1 and 10,000, got %d';
    public const MOCK_EXHAUSTED = 'MockHybridIdGenerator exhausted: all %d IDs have been consumed';

    // UuidConverter
    public const UUID_UNRECOGNIZED_PROFILE = 'Unrecognized profile index in UUIDv8';
    public const UUID_PACK_UNSUPPORTED = 'Profile "%s" cannot be losslessly packed into UUIDv8 (max 60 random bits)';
    public const UUID_NEGATIVE_TS = 'Timestamp must be non-negative';
    public const UUID_TS_OVERFLOW = 'Timestamp exceeds maximum encodable value (62^8 - 1)';
    public const UUID_NODE_INVALID = 'Node must be exactly 2 base62 characters';
    public const UUID_INVALID_FORMAT = 'Invalid UUID format';
    public const UUID_INVALID_VARIANT = 'Invalid UUID variant: expected RFC 4122 variant (10xx)';
    public const UUID_EXPECTED_VERSION = 'Expected UUID version %d, got %d';
    public const UUID_PROFILE_UNSUPPORTED = '%s() only supports compact and standard profiles (got "%s")';
    public const UUID_PREFIX_REJECTED = '%s() does not accept prefixed IDs — prefixes are lost during UUID conversion. Strip the prefix first with HybridIdGenerator::extractPrefix() and track it separately.';
    public const UUID_CONVERSION_INVALID = 'Invalid HybridId: cannot convert to %s';
    public const UUID_HEX_OVERFLOW = 'Hex value exceeds 64-bit integer range';

    // HybridIdGenerator
    public const GEN_REQUIRE_64BIT = 'HybridId requires 64-bit PHP';
    public const GEN_DRIFT_INVALID = 'maxDriftMs must be a positive integer, got %d';
    public const GEN_PROFILE_UNKNOWN = 'Unknown profile "%s"';
    public const GEN_BLIND_SECRET_LENGTH = 'blindSecret must be at least 32 bytes, got %d';
    public const GEN_NODE_INVALID = 'Node must be exactly 2 base62 characters (0-9, A-Z, a-z)';
    public const GEN_NODE_REQUIRED = 'Explicit node is required (requireExplicitNode is enabled). Provide a 2-character base62 node identifier via the node parameter or HYBRID_ID_NODE env var.';
    public const GEN_MAX_LENGTH_INVALID = 'maxIdLength (%d) must be >= body length (%d) for profile "%s"';
    public const GEN_ENV_PROFILE_INVALID = 'Invalid HYBRID_ID_PROFILE: "%s"';
    public const GEN_ENV_NODE_INVALID = 'Invalid HYBRID_ID_NODE: "%s". Must be exactly 2 base62 characters.';
    public const GEN_ENV_BLIND_SECRET_INVALID = 'HYBRID_ID_BLIND_SECRET must be valid base64';
    public const GEN_ENV_MAX_LENGTH_INVALID = 'Invalid HYBRID_ID_MAX_LENGTH: "%s". Must be a positive integer.';
    public const GEN_BATCH_LIMIT = 'Batch count must be between 1 and 10,000, got %d';
    public const GEN_PREFIX_LENGTH = 'maxPrefixLength must be between 0 and %d';
    public const GEN_DATETIME_FAILED = 'Failed to create DateTime from HybridId (timestamp: %d ms)';
    public const GEN_DRIFT_EXCEEDED = 'Monotonic timestamp drift exceeds %dms. Reduce generation rate or use multiple instances.';
    public const GEN_ID_LENGTH_EXCEEDED = 'Generated ID length %d exceeds maxIdLength %d. Use a shorter prefix or increase maxIdLength';
    public const GEN_ENCODE_LENGTH = 'Length must be at least 1';
    public const GEN_ENCODE_NEGATIVE = 'Cannot encode negative value';
    public const GEN_ENCODE_OVERFLOW = 'Value exceeds maximum for %d base62 characters';
    public const GEN_DECODE_EMPTY = 'Cannot decode empty string';
    public const GEN_DECODE_OVERFLOW = 'Value exceeds 64-bit integer range';
    public const GEN_DECODE_INVALID_CHAR = 'Invalid base62 character: %s';
    public const GEN_FORMAT_INVALID = 'Invalid HybridId format';
    public const GEN_PREFIX_FORMAT = 'Prefix must be 1-%d lowercase alphanumeric characters, starting with a letter';
    public const GEN_URANDOM_FAILED = 'Failed to generate cryptographically secure random bytes';
}
