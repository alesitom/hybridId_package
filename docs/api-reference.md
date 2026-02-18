# API Reference

Full reference for `HybridIdGenerator` — validation, metadata extraction, parsing, sorting, custom profiles, and introspection.

## Generation

### `generate(?string $prefix = null): string`

Generate an ID using the instance's configured profile.

```php
$gen = new HybridIdGenerator(node: 'A1');
$id = $gen->generate();        // 0VBFDQz4A1Rtntu09sbf
$id = $gen->generate('usr');   // usr_0VBFDQz4A1Rtntu09sbf
```

### Profile-specific generators

```php
$gen->compact('log');    // 16 chars: 8ts + 8rand
$gen->standard('usr');   // 20 chars: 8ts + 2node + 10rand (default)
$gen->extended('txn');   // 24 chars: 8ts + 2node + 14rand
```

### `generateBatch(int $count, ?string $prefix = null): array`

Generate multiple IDs with guaranteed monotonic ordering (max 10,000).

```php
$ids = $gen->generateBatch(100, 'evt');
// ['evt_...', 'evt_...', ...] — 100 unique, ordered IDs
```

Large batches advance the monotonic counter proportionally (e.g. 5,000 IDs = ~5s drift). Throws `IdOverflowException` if drift exceeds `MAX_DRIFT_MS` (5,000ms).

### `fromEnv(?ProfileRegistryInterface $registry = null): self` (static)

Construct a generator from environment variables. Useful for twelve-factor app configuration.

```php
$gen = HybridIdGenerator::fromEnv();
```

| Variable | Default | Description |
|---|---|---|
| `HYBRID_ID_PROFILE` | `standard` | Profile name: `compact`, `standard`, or `extended` (or any registered custom profile). |
| `HYBRID_ID_NODE` | `null` | Two-character base62 node identifier. Required when profile uses a node field and `HYBRID_ID_REQUIRE_NODE` is not `0`. |
| `HYBRID_ID_REQUIRE_NODE` | `1` (enabled) | Set to `0` to disable the node requirement guard. Has no effect on `compact` profile. |
| `HYBRID_ID_BLIND` | `0` (disabled) | Set to `1` or `true` to enable blind mode. |
| `HYBRID_ID_BLIND_SECRET` | `null` | Base64-encoded persistent HMAC secret. Decoded and passed as `blindSecret`. See [Blind Mode](blind-mode.md#persistent-secrets). |
| `HYBRID_ID_MAX_LENGTH` | `null` | Maximum allowed ID length (positive integer). Throws `IdOverflowException` when exceeded. |

Variables that are not set or are empty strings are treated as absent (the constructor default is used). Throws `\InvalidArgumentException` for malformed values.

## Validation

### `validate(string $id, ?string $expectedPrefix = null): bool` (instance)

Validate against **this instance's** profile configuration.

```php
$gen = new HybridIdGenerator(profile: 'standard', node: 'A1');
$gen->validate($id);              // true if body is 20 chars of base62
$gen->validate($id, 'usr');       // true if prefix is exactly 'usr'
```

### `isValid(string $id): bool` (static)

Validate against any known profile length (built-in profiles only).

```php
HybridIdGenerator::isValid('0VBFDQz4A1Rtntu09sbf');      // true
HybridIdGenerator::isValid('usr_0VBFDQz4A1Rtntu09sbf');   // true
HybridIdGenerator::isValid('not-valid');                    // false
```

> **Note:** Uses the global default registry. Custom profiles registered via an injected `ProfileRegistry` are not visible. Use `validate()` on an instance instead.

## Metadata Extraction

### `extractTimestamp(string $id): int` (static)

Returns the millisecond Unix timestamp.

```php
$ms = HybridIdGenerator::extractTimestamp($id);
// 1739750400000
```

### `extractDateTime(string $id): DateTimeImmutable` (static)

Returns a `DateTimeImmutable` with millisecond precision.

```php
$dt = HybridIdGenerator::extractDateTime($id);
echo $dt->format('Y-m-d H:i:s.v');
// 2026-02-17 00:00:00.000
```

### `extractNode(string $id): ?string` (static)

Returns the 2-char node identifier, or `null` for compact profile.

```php
$node = HybridIdGenerator::extractNode('0VBFDQz4A1Rtntu09sbf');
// 'A1'

$node = HybridIdGenerator::extractNode('0VBFDQz4xK9mLp2w');
// null (compact has no node)
```

### `extractPrefix(string $id): ?string` (static)

Returns the prefix, or `null` if unprefixed.

```php
HybridIdGenerator::extractPrefix('usr_0VBFDQz4A1Rtntu09sbf');
// 'usr'

HybridIdGenerator::extractPrefix('0VBFDQz4A1Rtntu09sbf');
// null
```

## Parsing

### `parse(string $id): array` (static)

Extract all components in a single pass. Always returns all keys.

```php
$result = HybridIdGenerator::parse('usr_0VBFDQz4A1Rtntu09sbf');
// [
//     'valid'     => true,
//     'prefix'    => 'usr',
//     'body'      => '0VBFDQz4A1Rtntu09sbf',
//     'profile'   => 'standard',
//     'timestamp' => 1739750400000,
//     'datetime'  => DateTimeImmutable,
//     'node'      => 'A1',
//     'random'    => 'Rtntu09sbf',
// ]
```

When `valid` is `false`, component keys (`profile`, `timestamp`, `datetime`, `node`, `random`) are `null`.

## Sorting

### `compare(string $a, string $b): int` (static)

Total ordering compatible with `usort()`. Primary: timestamp. Tiebreaker: lexicographic on body.

```php
usort($ids, [HybridIdGenerator::class, 'compare']);
```

Returns `0` only when both IDs are byte-identical after prefix stripping.

## Range Queries

### `minForTimestamp(int $timestampMs, Profile|string $profile = 'standard'): string`

Lowest possible ID for a timestamp. Use as inclusive lower bound.

### `maxForTimestamp(int $timestampMs, Profile|string $profile = 'standard'): string`

Highest possible ID for a timestamp. Use as inclusive upper bound.

```php
$start = strtotime('2026-01-01') * 1000;
$end   = strtotime('2026-02-01') * 1000;

$query = "SELECT * FROM orders
    WHERE id >= ? AND id <= ?";
// Bind: minForTimestamp($start), maxForTimestamp($end)
```

See [Database Guide](database.md) for more query patterns.

## Introspection

### `detectProfile(string $id): ?string` (static)

Detect profile by body length.

```php
HybridIdGenerator::detectProfile('0VBFDQz4A1Rtntu09sbf');
// 'standard'
```

### `profileConfig(Profile|string $profile): array` (static)

Get the config array for a profile.

```php
HybridIdGenerator::profileConfig('standard');
// ['length' => 20, 'ts' => 8, 'node' => 2, 'random' => 10]
```

### `profiles(): array` (static)

List all profile names (built-in + custom).

### `entropy(Profile|string $profile): float` (static)

Random entropy in bits.

```php
HybridIdGenerator::entropy('extended');
// 83.4
```

### `recommendedColumnSize(Profile|string $profile, int $maxPrefixLength = 0): int` (static)

Database column size helper.

```php
HybridIdGenerator::recommendedColumnSize('standard', 3);
// 24 (3 prefix + 1 underscore + 20 body)
```

### Getters

```php
$gen->getProfile();      // 'standard'
$gen->getNode();         // 'A1'
$gen->bodyLength();      // 20
$gen->getMaxIdLength();  // null or configured limit
$gen->isBlind();         // false
```

## Custom Profiles

Use `ProfileRegistry` to register custom profiles with different random lengths:

```php
use HybridId\ProfileRegistry;
use HybridId\HybridIdGenerator;

$registry = ProfileRegistry::withDefaults();
$registry->register('medium', 12); // 8ts + 2node + 12rand = 22 chars

$gen = new HybridIdGenerator(
    profile: 'medium',
    node: 'A1',
    registry: $registry,
);

$id = $gen->generate('evt'); // evt_<22chars>
```

Constraints:
- Name: lowercase alphanumeric, starts with a letter
- Random: 6-128 characters
- Total length must not conflict with existing profiles
- Custom profiles always include node (2 chars)

### Deprecated global registration

`registerProfile()` and `resetProfiles()` still work but are deprecated in v4. They mutate global static state and are unsafe in long-lived processes (Swoole, RoadRunner, Laravel Octane).

```php
// Deprecated — use ProfileRegistry injection instead
HybridIdGenerator::registerProfile('medium', 12);
HybridIdGenerator::resetProfiles();
```

## Prefix Rules

- 1-8 characters
- Lowercase alphanumeric only
- Must start with a letter
- Separator: `_` (Stripe convention)

All extraction and validation methods handle prefixed IDs transparently.

## Exceptions

| Exception | When |
|---|---|
| `InvalidProfileException` | Unknown profile name |
| `InvalidPrefixException` | Prefix format invalid |
| `InvalidIdException` | ID format invalid |
| `IdOverflowException` | Value exceeds capacity, drift exceeds limit, or maxIdLength exceeded |
| `NodeRequiredException` | Node required but not provided |

All implement `HybridIdException` (marker interface).
