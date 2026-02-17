# Blind Mode

HMAC-hash the timestamp and node with a per-instance secret, making creation time and node identity unextractable from the ID.

## Why

HybridId timestamps are predictable by design (same as UUIDv7). In some cases you don't want observers to know when an ID was created — user-facing IDs where registration timing could be exploited, or privacy-sensitive contexts where creation time is PII.

## Usage

```php
$gen = new HybridIdGenerator(node: 'A1', blind: true);
$id = $gen->generate('usr');  // usr_<opaque 20 chars>

// Works with all profiles
$gen = new HybridIdGenerator(profile: 'compact', blind: true);
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1', blind: true);

// From environment (HYBRID_ID_BLIND=1)
$gen = HybridIdGenerator::fromEnv();

// CLI
// ./vendor/bin/hybrid-id generate --blind
```

## How It Works

The constructor generates a 32-byte secret via `random_bytes(32)`. During generation:

1. Pack monotonic timestamp + node into binary
2. HMAC-SHA256 with the per-instance secret
3. Derive base62 characters from the HMAC output (replacing timestamp+node portion)
4. Append the random portion (unchanged)

```
Normal:  [timestamp][node][random]
Blind:   [HMAC(ts+node)  ][random]
```

Same length, same alphabet. An observer cannot tell if an ID is blind or not.

## What Works

- `isValid()`, `validate()`, `detectProfile()` — all work normally
- Same length, same prefix support, same collision resistance
- `generateBatch()` works
- UUID conversion technically works (but values are opaque)

## What Changes

- **No chronological sorting** — HMAC output is pseudo-random
- `extractTimestamp()` returns HMAC-derived value, not real time
- `minForTimestamp()`/`maxForTimestamp()` won't match blind IDs
- `extractNode()` returns HMAC-derived characters
- **Secret is ephemeral** — each instance gets a new secret, IDs from different instances have different mappings

## Node Handling

Blind mode bypasses `requireExplicitNode`:

```php
// Both valid — no NodeRequiredException
$gen = new HybridIdGenerator(blind: true);
$gen = new HybridIdGenerator(node: 'A1', blind: true);
```

If node is provided, it's included in the HMAC input. If not, one is auto-detected silently (used only as HMAC input, never appears in output).

## When NOT to Use

- **Not for security tokens** — entropy is unchanged. Use `random_bytes()` with 128+ bits for tokens, API keys, session IDs.
- **When you need sorting** — blind IDs aren't chronologically sortable.
- **When you need range queries** — `minForTimestamp()`/`maxForTimestamp()` won't work.
