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

When no `blindSecret` is provided, the constructor generates a 32-byte secret via `random_bytes(32)`. When `blindSecret` is provided, that value is used as the HMAC key instead. During generation:

1. Pack monotonic timestamp + node into binary
2. HMAC-SHA256 with the per-instance secret
3. Derive base62 characters from the HMAC output (replacing timestamp+node portion)
4. Append the random portion (unchanged)

```
Normal:  [timestamp][node][random]
Blind:   [HMAC(ts+node)  ][random]
```

Same length, same alphabet. An observer cannot tell if an ID is blind or not.

## Persistent Secrets

By default, the secret is ephemeral — generated fresh via `random_bytes(32)` on each constructor call. IDs from two separate instances are blinded with different secrets and have no shared mapping.

Pass a persistent secret via `blindSecret` to keep the mapping consistent across instances or restarts:

```php
// Generate a secret once and store it securely
$secret = random_bytes(32);

$gen = new HybridIdGenerator(node: 'A1', blind: true, blindSecret: $secret);
```

The `blindSecret` parameter accepts a raw binary string (`?string`). The value is used directly as the HMAC-SHA256 key.

### Via environment variable

`fromEnv()` reads `HYBRID_ID_BLIND_SECRET` as a base64-encoded secret:

```bash
# Generate and encode a secret once
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
# Store the output in your .env or secrets manager
```

```ini
HYBRID_ID_BLIND=1
HYBRID_ID_BLIND_SECRET=base64encodedvalue...
```

```php
// Picks up HYBRID_ID_BLIND and HYBRID_ID_BLIND_SECRET automatically
$gen = HybridIdGenerator::fromEnv();
```

`fromEnv()` throws `\InvalidArgumentException` if `HYBRID_ID_BLIND_SECRET` is set but is not valid base64.

### Security considerations

- Store the secret with the same care as a signing key — treat it as a credential.
- There is no built-in key rotation. Rotating the secret changes the blinding output for all future IDs but does not re-blind existing ones.
- Losing the secret does not expose historical IDs; it only means you can no longer produce the same blinded output for a given input.
- A persistent secret does not add cryptographic authentication. Blind mode is a privacy feature, not a MAC scheme.

## What Works

- `isValid()`, `validate()`, `detectProfile()` — all work normally
- Same length, same prefix support, same collision resistance
- `generateBatch()` works
- UUID conversion technically works (but values are opaque)

## What Changes

- **No chronological sorting** — HMAC output is not lexicographically sortable by time
- **Ordering analysis** — sequential blind IDs from the same instance reveal relative generation order (not absolute time), because the HMAC input is monotonically increasing
- `extractTimestamp()` returns HMAC-derived value, not real time
- `minForTimestamp()`/`maxForTimestamp()` won't match blind IDs
- `extractNode()` returns HMAC-derived characters
- **Secret is ephemeral by default** — each instance without a `blindSecret` gets a new secret; IDs from different instances have different mappings. Pass `blindSecret` or set `HYBRID_ID_BLIND_SECRET` to make the mapping persistent.

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
