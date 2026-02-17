# UUID Interoperability

Convert between HybridId and RFC 9562 UUIDs using `UuidConverter`.

## Overview

| Method | Version | Lossless | Profile support | Notes |
|---|---|---|---|---|
| `toUUIDv8()` / `fromUUIDv8()` | v8 | Yes | compact, standard | Profile auto-detected on decode |
| `toUUIDv7()` / `fromUUIDv7()` | v7 | No | compact, standard | Timestamp-preserving, needs profile hint |
| `toUUIDv4Format()` / `fromUUIDv4Format()` | v4 structure | No | compact, standard | Lossy, NOT a true UUIDv4 |

All methods reject prefixed IDs. Strip the prefix first and track it separately.

Extended profile is not supported (random portion exceeds UUID capacity).

## UUIDv8 (Lossless)

RFC 9562 UUIDv8 provides 122 custom bits. HybridId packs timestamp, node, profile index, and random into these bits for lossless round-trip.

```php
use HybridId\Uuid\UuidConverter;

$hybridId = '0VBFDQz4A1Rtntu09sbf';

// Convert to UUIDv8
$uuid = UuidConverter::toUUIDv8($hybridId);
// '0194ce80-0e04-8039-8000-0c5d1e5a3b7f'

// Convert back — profile is auto-detected
$restored = UuidConverter::fromUUIDv8($uuid);
// '0VBFDQz4A1Rtntu09sbf' (identical)
```

### Bit layout

```
Bits 0-47:   timestamp (48 bits, same as UUIDv7)
Bits 48-51:  version (1000 = v8)
Bits 52-63:  node value (12 bits, encodes 62^2 = 3844 values)
Bits 64-65:  variant (10 = RFC 4122)
Bits 66-67:  profile index (00 = compact, 01 = standard)
Bits 68-127: random (60 bits)
```

## UUIDv7 (Timestamp-preserving)

Preserves the millisecond timestamp in the standard UUIDv7 position. Random and node are packed into remaining bits. Not lossless — requires a profile hint on decode.

```php
// To UUIDv7
$uuid = UuidConverter::toUUIDv7($hybridId);

// From UUIDv7 — must specify profile
$restored = UuidConverter::fromUUIDv7($uuid, 'standard');
$restored = UuidConverter::fromUUIDv7($uuid, 'compact');
```

Timestamps are directly comparable with other UUIDv7 implementations since they occupy the same bit positions.

## UUIDv4 Format (Lossy)

Packs HybridId data into UUID v4 structure (version=4, variant=10xx). The output is **not** a true UUIDv4 — it's deterministically derived, not 122 bits of random.

```php
// To v4 format
$uuid = UuidConverter::toUUIDv4Format($hybridId);

// From v4 format — needs original timestamp and node
$restored = UuidConverter::fromUUIDv4Format(
    $uuid,
    profile: 'standard',
    timestampMs: $originalTimestamp,
    node: 'A1',
);
```

Use this only for systems that strictly require v4-formatted UUIDs. Prefer `toUUIDv8()` for new integrations.

## Prefixed IDs

All `to*()` methods reject prefixed IDs to prevent silent prefix loss:

```php
// Throws InvalidIdException
UuidConverter::toUUIDv8('usr_0VBFDQz4A1Rtntu09sbf');

// Correct: strip prefix first
$prefix = HybridIdGenerator::extractPrefix($id);   // 'usr'
$body = substr($id, strlen($prefix) + 1);           // '0VBFDQz4A1Rtntu09sbf'
$uuid = UuidConverter::toUUIDv8($body);
```

## Blind Mode

UUID conversion technically works on blind IDs (the values are valid base62), but the encoded timestamp and node are HMAC-derived — they don't represent real time or identity. Round-trip won't reproduce the original HybridId from a different generator instance.

## Migration from UUID

If migrating from UUIDv4 to HybridId, use `fromUUIDv4Format()` to convert existing records:

```php
// During migration, supply the creation timestamp if known
$hybridId = UuidConverter::fromUUIDv4Format(
    $existingUuid,
    profile: 'standard',
    timestampMs: $createdAtMs,
    node: 'A1',
);
```

See [Database Guide](database.md#migration-from-uuid) for full migration strategies.
