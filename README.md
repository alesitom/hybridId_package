# HybridId

**Compact, time-sortable unique identifiers for PHP**

[![Packagist Version](https://img.shields.io/packagist/v/alesitom/hybrid-id.svg?style=flat-square)](https://packagist.org/packages/alesitom/hybrid-id)
[![PHP Requirement](https://img.shields.io/packagist/php-v/alesitom/hybrid-id.svg?style=flat-square)](https://packagist.org/packages/alesitom/hybrid-id)
[![License](https://img.shields.io/packagist/l/alesitom/hybrid-id.svg?style=flat-square)](https://github.com/alesitom/hybridId_package/blob/main/LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/alesitom/hybridId_package/ci.yml?style=flat-square&label=tests)](https://github.com/alesitom/hybridId_package/actions)

A space-efficient alternative to UUID with configurable entropy profiles, Stripe-style prefixes, and an instance-based API. Generate chronologically sortable, URL-safe identifiers 33-56% smaller than canonical UUIDs — with zero dependencies.

## Why HybridId?

| Feature | HybridId | TypeID | KSUID | UUIDv7 | NanoID | CUID2 |
|---|---|---|---|---|---|---|
| Length | 16-24 chars | 26 chars | 27 chars | 36 chars | 21 chars | 24 chars |
| Configurable size | Yes | No | No | No | No | No |
| Type prefixes | Yes | Yes | No | No | No | No |
| Time-sortable | Yes | Yes | Yes | Yes | No | No |
| Metadata extraction | Full | Partial | Partial | Partial | None | None |
| Zero dependencies | Yes | Varies | Varies | Yes | Varies | Varies |
| Range queries | Yes | No | No | No | No | No |
| Multi-node safe | Yes | Yes | No | Yes | N/A | N/A |
| Random entropy | 47.6 - 83.4+ bits | ~80 bits | 128 bits | 74 bits | ~126 bits | ~120 bits |

## Installation

```bash
composer require alesitom/hybrid-id
```

Requires PHP 8.3, 8.4, or 8.5 (64-bit). No external dependencies.

## Quick Start

```php
use HybridId\HybridIdGenerator;

$gen = new HybridIdGenerator(node: 'A1');

$id = $gen->generate();        // 0VBFDQz4A1Rtntu09sbf
$id = $gen->generate('usr');   // usr_0VBFDQz4A1Rtntu09sbf
$id = $gen->compact('log');    // log_0VBFDQz6xK9mLp2w
$id = $gen->extended('txn');   // txn_0VBFDQz7A1pBKVwwn2xiF0
```

## Profiles

Three built-in profiles with different size/entropy tradeoffs:

| Profile | Length | Structure | Random entropy | Use case |
|---|---|---|---|---|
| `compact` | 16 | 8ts + 8rand | 47.6 bits | Internal PKs, low-scale apps |
| `standard` | 20 | 8ts + 2node + 10rand | 59.5 bits | General purpose (default) |
| `extended` | 24 | 8ts + 2node + 14rand | 83.4 bits | High-scale, public-facing IDs |

```
Standard / Extended:          Compact (no node):

0VBFDQz4 A1 Rtntu09sbf       0VBFDQz4 xK9mLp2w
|______| |_| |_________|      |______| |________|
   ts   node   random            ts      random
```

- **ts** (8 chars): Millisecond timestamp in base62. Enables chronological sorting.
- **node** (2 chars, standard/extended): Server/process identifier. Prevents cross-node collisions.
- **rand** (variable): Cryptographically secure random bytes via `random_bytes()`.

Custom profiles are available via `ProfileRegistry` — see [API Reference](docs/api-reference.md#custom-profiles).

## Configuration

```php
use HybridId\HybridIdGenerator;

// Standard profile with explicit node (recommended for production)
$gen = new HybridIdGenerator(node: 'A1');

// Explicit profile
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');

// Compact — no node needed
$gen = new HybridIdGenerator(profile: 'compact');

// From environment variables (HYBRID_ID_PROFILE, HYBRID_ID_NODE, HYBRID_ID_BLIND, HYBRID_ID_BLIND_SECRET)
$gen = HybridIdGenerator::fromEnv();
```

By default, standard and extended profiles **require** an explicit node to prevent accidental collisions in production. Pass `requireExplicitNode: false` for local development.

## Prefixes

Stripe-style prefixes make IDs self-documenting:

```php
$gen->generate('usr');   // usr_0VBFDQz4A1Rtntu09sbf
$gen->generate('ord');   // ord_0VBFDQz5A1xiF0G9pBKV
```

Rules: 1-8 chars, lowercase alphanumeric, starts with a letter. All extraction and validation methods handle prefixed IDs transparently.

## Database

### Column sizing

| Profile | No prefix | With prefix (max 3) | With prefix (max 8) |
|---|---|---|---|
| `compact` | `CHAR(16)` | `VARCHAR(20)` | `VARCHAR(25)` |
| `standard` | `CHAR(20)` | `VARCHAR(24)` | `VARCHAR(29)` |
| `extended` | `CHAR(24)` | `VARCHAR(28)` | `VARCHAR(33)` |

### Collation (MySQL/MariaDB)

Base62 uses mixed case (`A` != `a`). You **must** use `ascii_bin` or `utf8mb4_bin` collation — the default `utf8mb4_0900_ai_ci` will silently break uniqueness and sort order.

```sql
CREATE TABLE users (
    id CHAR(20) COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ...
);
```

PostgreSQL and SQLite are case-sensitive by default — no special collation needed.

### Storage efficiency

| Format | Size | Savings vs UUID |
|--------|------|-----------------|
| UUID (canonical) | CHAR(36) | — |
| ULID | CHAR(26) | 28% |
| TypeID | VARCHAR(34) | 6% |
| HybridId compact | CHAR(16) | 56% |
| HybridId standard | CHAR(20) | 44% |
| HybridId extended | CHAR(24) | 33% |

Smaller primary keys improve B-tree index density and reduce page splits. Time-sorted layout eliminates the random-insert penalty of UUID v4. See [Database Guide](docs/database.md) for time-range queries, NoSQL patterns, and migration strategies.

## Security

**Not for secrets.** Do NOT use HybridId for security tokens, session IDs, API keys, or password resets. The timestamp is predictable — use `random_bytes()` with 128+ bits for those.

**Standards alignment:**
- [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562): UUIDv8 compliant via `UuidConverter::toUUIDv8()`
- CSPRNG: `random_bytes()` backed by OS-level cryptographic random
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986): URL-safe base62, no percent-encoding needed
- Rejection sampling eliminates modulo bias (NIST SP 800-90A aligned)

**What HybridId is NOT:** not OWASP ASVS V2.6 compliant, not constant-time in validation, timestamps are predictable by design (same as UUIDv7).

## Blind Mode

HMAC-hashes the timestamp and node with a per-instance secret, making creation time unextractable. Same length and format — an observer cannot tell if an ID is blind.

```php
$gen = new HybridIdGenerator(node: 'A1', blind: true);
$id = $gen->generate('usr');  // usr_<opaque20chars>
```

See [Blind Mode](docs/blind-mode.md) for details on what works, what changes, and when to use it.

## UUID Interoperability

Convert between HybridId and RFC 9562 UUIDs:

| Method | Lossless | Notes |
|--------|----------|-------|
| `UuidConverter::toUUIDv8()` / `fromUUIDv8()` | Yes | Profile auto-detected on decode |
| `UuidConverter::toUUIDv7()` / `fromUUIDv7()` | No | Timestamp-preserving, needs profile hint |
| `UuidConverter::toUUIDv4Format()` / `fromUUIDv4Format()` | No | Lossy, NOT a true UUIDv4 |

Compact and standard profiles only. Prefixed IDs are rejected — strip prefix first.

See [UUID Interoperability](docs/uuid-interoperability.md) for full examples and compatibility matrix.

## Framework Integrations

| Package | Framework | Install |
|---|---|---|
| [hybrid-id-laravel](https://github.com/alesitom/hybrid-id-laravel) | Laravel 11/12 | `composer require alesitom/hybrid-id-laravel` |
| [hybrid-id-doctrine](https://github.com/alesitom/hybrid-id-doctrine) | Doctrine DBAL 4 / ORM 3 | `composer require alesitom/hybrid-id-doctrine` |

See [Dependency Injection & Testing](docs/dependency-injection.md) for `IdGenerator` interface, DI wiring, and framework examples.

## Requirements

- PHP 8.3, 8.4, or 8.5 (64-bit)
- No external dependencies

## Learn More

| Topic | Link |
|---|---|
| Full API (validation, parsing, metadata, sorting, custom profiles) | [docs/api-reference.md](docs/api-reference.md) |
| UUID conversion (v8, v7, v4-format) | [docs/uuid-interoperability.md](docs/uuid-interoperability.md) |
| Database (time-range queries, NoSQL, migration from UUID) | [docs/database.md](docs/database.md) |
| Blind mode (HMAC-hashed timestamps) | [docs/blind-mode.md](docs/blind-mode.md) |
| CLI reference | [docs/cli.md](docs/cli.md) |
| Dependency injection & testing | [docs/dependency-injection.md](docs/dependency-injection.md) |
| Internals (clock drift, concurrency, design decisions) | [docs/internals.md](docs/internals.md) |
| Upgrading (v1 → v2 → v3 → v4) | [UPGRADING.md](UPGRADING.md) |
| Changelog | [CHANGELOG.md](CHANGELOG.md) |

## License

MIT
