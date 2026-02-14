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

- **`compact`**: Internal PKs, low-scale apps, storage-constrained systems
- **`standard`**: General purpose, recommended default
- **`extended`**: High-scale, public-facing IDs, when you need more entropy than UUID v7

> **Security note:** Do NOT use HybridId for security tokens (password resets, API keys, etc.). The timestamp is predictable and reduces effective entropy. Use `random_bytes()` with 128+ bits of pure entropy for those.

## Clock Drift Protection

Includes a monotonic guard that prevents the timestamp from going backward due to NTP adjustments. If the system clock moves backward, the last known timestamp is preserved.

## Requirements

- PHP >= 8.2
- No external dependencies

## License

MIT
