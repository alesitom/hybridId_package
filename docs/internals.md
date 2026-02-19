# Internals

Design decisions and implementation details for contributors and advanced users.

## Monotonic Guard & Clock Drift

Each `HybridIdGenerator` instance tracks its last-used timestamp. If the clock drifts backward or two IDs are generated in the same millisecond, the timestamp is incremented:

```
Real time:  1000, 1001, 1001, 1001, 1002
Used:       1000, 1001, 1002, 1003, 1004  (monotonically increasing)
```

This guarantees strict ordering within an instance but introduces "drift" — the difference between the monotonic counter and real wall-clock time.

### Drift cap

`MAX_DRIFT_MS = 5000`. If the counter drifts more than 5 seconds ahead of real time, `IdOverflowException` is thrown. This prevents unbounded future-dated timestamps under sustained high throughput.

Practical impact: ~5,000 IDs/ms sustained before the cap triggers. Normal workloads never hit this.

### Forward clock jumps

If the system clock jumps forward (e.g. NTP correction), the guard naturally catches up — no special handling needed.

## Concurrency

`HybridIdGenerator` is **not** thread-safe. Each thread, coroutine, or worker (Swoole, ReactPHP, Laravel Octane) must use its own instance. Sharing instances across concurrent contexts causes timestamp collisions.

The monotonic guard is per-instance, so independent instances can generate IDs with the same millisecond timestamp. The node + random portions prevent collisions across instances.

## Node Auto-Detection

When no explicit node is provided, `random_bytes(2)` generates a random 2-character node. This is a dev/testing fallback — production should always use explicit nodes.

Modulo bias: `65536 % 3844 = 120`, so values `[0, 119]` are ~0.003% more likely. Negligible for a non-deterministic fallback.

Previous versions used `crc32(hostname:pid)` which was deterministic but could collide across containers with identical hostnames.

## Base62 Encoding

Alphabet: `0-9A-Za-z` (62 characters, URL-safe, no percent-encoding needed).

The encoding is big-endian with zero-padding to fixed length. This preserves sort order: lexicographic string comparison matches numeric comparison.

### Rejection sampling

`randomBase62()` uses rejection sampling to eliminate modulo bias. Each random byte is accepted only if `< 248` (largest multiple of 62 that fits in a byte). Rejected bytes are discarded and new ones generated.

Expected overhead: ~3.1% extra bytes. Buffer pre-allocation (`ceil(length * 1.25)`) minimizes `random_bytes()` calls.

## Overflow Detection

`decodeBase62()` checks for 64-bit integer overflow before each multiply-add step:

```php
if ($result > intdiv(PHP_INT_MAX - $pos, 62)) {
    throw new IdOverflowException('Value exceeds 64-bit integer range');
}
```

This replaced an earlier `is_float()` check which was unreliable on some PHP builds.

## Why No Version Byte

Unlike ULID or TypeID, HybridId doesn't embed a version identifier in the ID. Reasons:

- Every character is precious at 16-24 chars — a version byte costs ~6 bits of entropy
- Profile detection works by length (16/20/24 for built-in profiles)
- Breaking format changes get a new major version, not a new byte

## Blind Mode HMAC (SHA-384)

Input: `pack('J', timestamp) . node` (big-endian 64-bit int + 2-char node).
Key: `random_bytes(32)` generated once per instance (or persistent via `blindSecret`).
Algorithm: `hash_hmac('sha384', input, key, binary: true)`.
Output: per-character derivation from 16-bit pairs of HMAC bytes, each `% 62`, for `opaqueLen` characters.

The per-character `% 62` on 16-bit values introduces ~0.003% modulo bias, which is acceptable — the HMAC output is for privacy (making timestamps unextractable), not for cryptographic key material.

## Prefix Design

Stripe convention: `{type}_{id}`. Constraints:
- 1-8 lowercase alphanumeric characters, starting with a letter
- Separator: `_` (single underscore)
- IDs with multiple underscores in the body are rejected by `stripPrefix()`

Prefixes are metadata, not part of the ID body. All comparison, extraction, and UUID conversion methods strip prefixes before operating.
