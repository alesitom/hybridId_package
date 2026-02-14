# HybridId

Compact, time-sortable unique ID generator for PHP. A space-efficient alternative to UUID with configurable entropy profiles.

## Why HybridId?

| Feature | UUID v4 (36 chars) | UUID v7 (36 chars) | HybridId (16-24 chars) |
|---|---|---|---|
| Time-sortable | No | Yes | Yes |
| URL-safe | No (hyphens) | No (hyphens) | Yes (base62) |
| Storage | 36 bytes text | 36 bytes text | 16-24 bytes |
| Human-readable | Low | Low | High |
| Random entropy | 122 bits | 74 bits | 35.7 - 83.4 bits |

## Installation

```bash
composer require alesitom/hybrid-id
```

## Quick Start

```php
use HybridId\HybridId;

$id = HybridId::generate(); // 20-char standard ID
```

## Profiles

Three profiles with different size/entropy tradeoffs:

| Profile | Length | Structure | Random entropy | vs UUID v7 (74 bits) |
|---|---|---|---|---|
| `compact` | 16 | 8ts + 2node + 6rand | 35.7 bits | Lower |
| `standard` | 20 | 8ts + 2node + 10rand | 59.5 bits | Comparable |
| `extended` | 24 | 8ts + 2node + 14rand | 83.4 bits | Higher |

**Structure:**
- **ts** (8 chars): Millisecond timestamp in base62. Enables chronological sorting. Covers ~6,920 years.
- **node** (2 chars): Server/process identifier. Prevents cross-node collisions.
- **rand** (variable): Cryptographically secure random (`random_int`). Prevents same-millisecond collisions.

## Configuration

### Via code

```php
use HybridId\HybridId;

HybridId::configure([
    'profile' => 'standard',   // compact | standard | extended
    'node'    => 'A1',         // 2 base62 chars, or omit for auto-detection
]);
```

### Via environment variables

```php
// In your bootstrap or service provider
HybridId::configureFromEnv();
```

Reads from:
```env
HYBRID_ID_PROFILE=standard
HYBRID_ID_NODE=A1
```

For `.env` file support, install [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv):

```bash
composer require vlucas/phpdotenv
```

```php
// bootstrap.php
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

HybridId::configureFromEnv();
```

### Node auto-detection

When no node is configured, HybridId derives a deterministic 2-char identifier from `gethostname()` and `getmypid()`. For multi-server deployments, set an explicit node per instance via environment variable.

## Usage

### Generate

```php
$id = HybridId::generate();     // configured default profile
$id = HybridId::compact();      // 16 chars
$id = HybridId::standard();     // 20 chars
$id = HybridId::extended();     // 24 chars
```

### Validate

```php
HybridId::isValid($id);            // true | false
HybridId::detectProfile($id);      // "standard" | "compact" | "extended" | null
```

### Extract metadata

```php
HybridId::extractTimestamp($id);    // int (milliseconds since epoch)
HybridId::extractDateTime($id);    // DateTimeImmutable
HybridId::extractNode($id);        // string (2 chars)
```

### Introspection

```php
HybridId::entropy();                // 59.5 (bits for current profile)
HybridId::entropy('extended');      // 83.4
HybridId::profiles();               // ['compact', 'standard', 'extended']
HybridId::profileConfig('compact'); // ['length' => 16, 'ts' => 8, 'node' => 2, 'random' => 6]
```

## CLI

```bash
# Generate IDs
./vendor/bin/hybrid-id generate                     # 1 standard ID
./vendor/bin/hybrid-id generate -p compact -n 10    # 10 compact IDs
./vendor/bin/hybrid-id generate -p extended --node A1

# Inspect an existing ID
./vendor/bin/hybrid-id inspect <id>

# Show profile comparison
./vendor/bin/hybrid-id profiles
```

## Database Usage

```sql
CREATE TABLE users (
    id CHAR(20) NOT NULL PRIMARY KEY,  -- standard profile
    ...
);

CREATE TABLE logs (
    id CHAR(16) NOT NULL PRIMARY KEY,  -- compact profile
    ...
);
```

**Why CHAR(N) works well:**
- Fixed-width columns are efficient for B-tree indexes
- Chronological ordering means sequential inserts, reducing page splits
- Smaller than CHAR(36) UUIDs, improving index density and JOIN performance

## Choosing a Profile

- **`compact` (16 chars)**: Internal PKs, low-scale apps, storage-constrained systems. ~35.7 bits entropy means 50% collision probability at ~236,000 IDs per millisecond per node. Not recommended for high-throughput systems.
- **`standard` (20 chars)**: General purpose, recommended default. ~59.5 bits provides comfortable collision resistance for most applications.
- **`extended` (24 chars)**: High-scale, public-facing IDs, when you need more entropy than UUID v7. ~83.4 bits of random entropy.

## Security Considerations

**Not for secrets:** Do NOT use HybridId for security tokens (password resets, API keys, session tokens, etc.). The timestamp is predictable and reduces effective entropy. Use `random_bytes()` with 128+ bits of pure entropy for those.

**Timestamp disclosure:** The first 8 characters encode the creation time to the millisecond. Anyone with a HybridId can extract when it was created and which node generated it. This is inherent to the design (same as UUID v7). Do not use HybridId where creation time must be confidential.

**Validation is not constant-time:** `isValid()` returns early on the first invalid character. If you compare HybridIds in security-sensitive contexts (e.g., authorization), use `hash_equals()` instead of `===` to prevent timing side-channels.

## Clock Drift Protection

The monotonic guard ensures timestamps never go backward and strictly increment even within the same millisecond. If the system clock moves backward (NTP adjustment), or multiple IDs are generated in the same millisecond, the timestamp increments by 1ms to guarantee strict chronological ordering.

## Concurrency and Limitations

**Per-process scope:** The monotonic guard uses static state that is scoped to a single PHP process. In PHP-FPM or mod_php, each request is a separate process with independent state. Two concurrent requests in the same millisecond on the same node rely on the random component for uniqueness.

**Async runtimes:** In long-running processes (Swoole, ReactPHP, Amphp), the static state is shared within the process. The monotonic guard works correctly under cooperative scheduling (PHP Fibers), but has no atomicity guarantees under preemptive coroutines.

**Node auto-detection:** The auto-detected node is derived from `gethostname()` and `getmypid()` via `crc32()`, reduced to 3,844 possible values. In clustered deployments with many processes, always set `HYBRID_ID_NODE` explicitly to guarantee node uniqueness.

## Requirements

- PHP >= 8.3 (64-bit)
- No external dependencies

## License

MIT
