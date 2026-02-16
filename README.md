# HybridId

Compact, time-sortable unique ID generator for PHP. A space-efficient alternative to UUID with configurable entropy profiles, Stripe-style prefixes, and an instance-based API.

## Why HybridId?

| Feature | UUID v4 | UUID v7 | ULID | Snowflake | HybridId |
|---|---|---|---|---|---|
| Length | 36 chars | 36 chars | 26 chars | 18-19 digits | 16-24+ chars |
| Time-sortable | No | Yes | Yes | Yes | Yes |
| URL-safe | No (hyphens) | No (hyphens) | Yes | Yes | Yes (base62) |
| Human-readable | Low | Low | Medium | Low | High |
| Self-documenting | No | No | No | No | Yes (prefixes) |
| Multi-node safe | Yes | Yes | No | Yes (node bits) | Yes (node chars) |
| Configurable size | No | No | No | No | Yes (profiles) |
| Random entropy | 122 bits | 74 bits | 80 bits | 12 bits | 35.7 - 83.4+ bits |
| Dependencies | None | None | Library | Library | None |

## Installation

```bash
composer require alesitom/hybrid-id
```

Requires PHP >= 8.3 (64-bit). No external dependencies.

## Quick Start

```php
use HybridId\HybridIdGenerator;

$gen = new HybridIdGenerator();

$id = $gen->generate();        // 0VBFDQz4CYRtntu09sbf
$id = $gen->generate('usr');   // usr_0VBFDQz4CYRtntu09sbf
```

## Profiles

Three built-in profiles with different size/entropy tradeoffs:

| Profile | Length | Structure | Random entropy | vs UUID v7 (74 bits) |
|---|---|---|---|---|
| `compact` | 16 | 8ts + 2node + 6rand | 35.7 bits | Lower |
| `standard` | 20 | 8ts + 2node + 10rand | 59.5 bits | Comparable |
| `extended` | 24 | 8ts + 2node + 14rand | 83.4 bits | Higher |

**Structure breakdown:**

```
0VBFDQz4 CY Rtntu09sbf
|______| |_| |_________|
   ts   node   random
```

- **ts** (8 chars): Millisecond timestamp in base62. Enables chronological sorting. Covers ~6,920 years from epoch.
- **node** (2 chars): Server/process identifier. Prevents cross-node collisions.
- **rand** (variable): Cryptographically secure random bytes. Prevents same-millisecond collisions.

## Creating a Generator

### Via constructor

```php
use HybridId\HybridIdGenerator;

// Default: standard profile, auto-detected node
$gen = new HybridIdGenerator();

// Explicit profile and node
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');

// With column size guard (throws OverflowException if prefix + body exceeds limit)
$gen = new HybridIdGenerator(profile: 'extended', maxIdLength: 32);
```

### Via environment variables

```php
$gen = HybridIdGenerator::fromEnv();
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
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$gen = HybridIdGenerator::fromEnv();
```

### Node auto-detection

When no node is provided, the generator derives a deterministic 2-char identifier from `gethostname()` and `getmypid()`, yielding 3,844 possible values (62²). By the birthday paradox, 50% collision probability is reached at ~74 distinct host:pid pairs. For multi-server deployments or environments with many worker processes, always set an explicit node per instance to guarantee uniqueness.

## Generating IDs

```php
$gen = new HybridIdGenerator();

// Using the instance's configured profile (default: standard)
$id = $gen->generate();       // 0VBFDQz4CYRtntu09sbf

// Explicit profile methods
$id = $gen->compact();        // 0VBFDQz4CY8xegI0
$id = $gen->standard();       // 0VBFDQz5CYS0PQgr0sbf
$id = $gen->extended();       // 0VBFDQz6CYxiF0G9pBKVwwn2
```

## Prefixes

Stripe-style prefixes make IDs self-documenting. Pass an optional prefix to any generation method:

```php
$gen = new HybridIdGenerator();

$id = $gen->generate('usr');   // usr_0VBFDQz4CYRtntu09sbf
$id = $gen->generate('ord');   // ord_0VBFDQz5CYxiF0G9pBKV
$id = $gen->compact('log');    // log_0VBFDQz6CY8xegI0
$id = $gen->extended('txn');   // txn_0VBFDQz7CYpBKVwwn2xiF0
```

Prefix rules:
- 1 to 8 characters
- Lowercase alphanumeric only, must start with a letter
- Separated by underscore (`_`)
- Optional: omit for unprefixed IDs

All extraction and validation methods handle prefixed IDs transparently.

## Multiple Generators

Each instance has its own profile, node, and monotonic counter. Use multiple generators for different entity types:

```php
$userIds = new HybridIdGenerator(profile: 'extended', node: 'U1');
$logIds  = new HybridIdGenerator(profile: 'compact', node: 'L1');

$userId = $userIds->generate('usr');  // usr_... (24 char ID)
$logId  = $logIds->generate('log');   // log_... (16 char ID)
```

Instance state is fully independent -- different monotonic counters, different nodes, no cross-contamination.

## Validation

### Static validation (any profile)

```php
use HybridId\HybridIdGenerator;

HybridIdGenerator::isValid('0VBFDQz4CYRtntu09sbf');       // true
HybridIdGenerator::isValid('usr_0VBFDQz4CYRtntu09sbf');   // true
HybridIdGenerator::isValid('invalid');                      // false

HybridIdGenerator::detectProfile('0VBFDQz4CYRtntu09sbf');  // "standard"
HybridIdGenerator::detectProfile('usr_0VBFDQz4CY8xegI0');  // "compact"
HybridIdGenerator::detectProfile('bad');                    // null
```

### Instance validation (profile-aware)

`validate()` checks that an ID matches **this instance's profile** and optionally a specific prefix:

```php
$gen = new HybridIdGenerator(profile: 'extended');

$gen->validate($extendedId);              // true — body length matches extended (24)
$gen->validate($standardId);              // false — body length is 20, not 24
$gen->validate($extendedId, 'ord');       // true — profile matches AND prefix is 'ord'
$gen->validate($extendedId, 'usr');       // false — prefix mismatch
```

This is a format check, not an authorization mechanism.

## Extracting Metadata

Every HybridId encodes its creation time, generating node, and optional prefix:

```php
use HybridId\HybridIdGenerator;

$gen = new HybridIdGenerator(node: 'A1');
$id = $gen->generate('usr');   // usr_0VBFDQz4A1Rtntu09sbf

HybridIdGenerator::extractTimestamp($id);  // 1771109611324 (ms since epoch)
HybridIdGenerator::extractDateTime($id);   // DateTimeImmutable (2026-02-14 22:53:31.324)
HybridIdGenerator::extractNode($id);       // "A1"
HybridIdGenerator::extractPrefix($id);     // "usr"
HybridIdGenerator::extractPrefix($gen->generate());  // null (no prefix)
```

## Parsing

Extract all components in a single call with `parse()`:

```php
$result = HybridIdGenerator::parse('usr_0VB0Td2u01mcw1hoy5Kg8mR');
// [
//     'valid'     => true,
//     'prefix'    => 'usr',
//     'profile'   => 'extended',
//     'body'      => '0VB0Td2u01mcw1hoy5Kg8mR',
//     'timestamp' => 1708012345678,
//     'datetime'  => DateTimeImmutable,
//     'node'      => '01',
//     'random'    => 'mcw1hoy5Kg8mR',
// ]

// Invalid IDs return partial data with valid => false
$result = HybridIdGenerator::parse('not_valid');
// ['valid' => false, 'prefix' => 'not', 'body' => 'valid']
```

## Sorting

Compare IDs chronologically with `compare()`, compatible with `usort()`:

```php
use HybridId\HybridIdGenerator;

$gen = new HybridIdGenerator();
$ids = [];

for ($i = 0; $i < 100; $i++) {
    $ids[] = $gen->generate();
}

shuffle($ids);

// Sort chronologically
usort($ids, HybridIdGenerator::compare(...));

// Works with prefixed IDs too -- prefixes are stripped before comparison
$mixed = [$gen->generate('usr'), $gen->generate('ord'), $gen->generate('log')];
usort($mixed, HybridIdGenerator::compare(...));
```

## Introspection

```php
use HybridId\HybridIdGenerator;

HybridIdGenerator::entropy('compact');       // 35.7
HybridIdGenerator::entropy('standard');      // 59.5
HybridIdGenerator::entropy('extended');      // 83.4

HybridIdGenerator::profiles();               // ['compact', 'standard', 'extended']

HybridIdGenerator::profileConfig('compact');
// ['length' => 16, 'ts' => 8, 'node' => 2, 'random' => 6]

$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');
$gen->getProfile();      // "extended"
$gen->getNode();         // "A1"
$gen->bodyLength();      // 24
$gen->getMaxIdLength();  // null (no limit set)
```

## Custom Profiles

Register profiles with custom random lengths. Timestamp (8) and node (2) are fixed -- only the random portion is configurable:

```php
use HybridId\HybridIdGenerator;

// Register a 32-char profile: 8ts + 2node + 22random
HybridIdGenerator::registerProfile('ultra', 22);

$gen = new HybridIdGenerator(profile: 'ultra');
$id = $gen->generate('txn');

strlen($id);                                    // 36 (3 prefix + 1 underscore + 32)
HybridIdGenerator::detectProfile($id);          // "ultra"
HybridIdGenerator::entropy('ultra');             // 130.9

// Register a minimal 12-char profile: 8ts + 2node + 2random
HybridIdGenerator::registerProfile('tiny', 2);
```

Constraints:
- Profile name must be lowercase alphanumeric, starting with a letter
- Random length must be between 6 and 128
- Total length must not conflict with an existing profile

## Interface and Dependency Injection

`HybridIdGenerator` implements the `IdGenerator` interface for clean DI and testing:

```php
use HybridId\IdGenerator;
use HybridId\HybridIdGenerator;

class UserService
{
    public function __construct(
        private readonly IdGenerator $idGenerator,
    ) {}

    public function createUser(string $name): User
    {
        return new User(
            id: $this->idGenerator->generate('usr'),
            name: $name,
        );
    }
}

// Production
$service = new UserService(new HybridIdGenerator(profile: 'extended', node: 'A1'));

// Testing
$mock = $this->createMock(IdGenerator::class);
$mock->method('generate')->willReturn('usr_testid12345678AB');
$service = new UserService($mock);
```

The interface:

```php
interface IdGenerator
{
    public function generate(?string $prefix = null): string;
}
```

## CLI

```bash
# Generate IDs
./vendor/bin/hybrid-id generate
# 0VBFDQz4CYRtntu09sbf

./vendor/bin/hybrid-id generate -p compact -n 5
# 0VBFDQz4CY8xegI0
# 0VBFDQz5CYRtntu0
# 0VBFDQz6CY9jLlWd
# 0VBFDQz7CYDexq1t
# 0VBFDQz8CY8beN74

./vendor/bin/hybrid-id generate --prefix usr --node A1
# usr_0VBFDQz4A1Rtntu09sbf

./vendor/bin/hybrid-id generate -p extended --prefix txn -n 3
# txn_0VBFDQz4CYxiF0G9pBKVwwn2
# txn_0VBFDQz5CYS0PQgr0sbfAbCd
# txn_0VBFDQz6CYRtntu09sbfXyZw
```

```bash
# Inspect an existing ID
./vendor/bin/hybrid-id inspect usr_0VBFDQz4A1Rtntu09sbf

#   ID:         usr_0VBFDQz4A1Rtntu09sbf
#   Prefix:     usr
#   Profile:    standard (20 chars)
#   Timestamp:  1771109611324
#   DateTime:   2026-02-14 22:53:31.324
#   Node:       A1
#   Random:     Rtntu09sbf
#   Entropy:    59.5 bits
#   Valid:      yes
```

```bash
# Show all profiles
./vendor/bin/hybrid-id profiles

#   Profile     Length   Structure              Random bits   vs UUID v7
#   -------     ------   ---------              -----------   ----------
#   compact     16       8ts + 2node + 6rand    35.7 bits     < UUID v7
#   standard    20       8ts + 2node + 10rand   59.5 bits     ~ UUID v7
#   extended    24       8ts + 2node + 14rand   83.4 bits     > UUID v7
```

## Database Usage

### VARCHAR sizing reference

| Profile | Body | No prefix | With prefix (max 3) | With prefix (max 8) |
|---|---|---|---|---|
| `compact` | 16 | `CHAR(16)` | `VARCHAR(20)` | `VARCHAR(25)` |
| `standard` | 20 | `CHAR(20)` | `VARCHAR(24)` | `VARCHAR(29)` |
| `extended` | 24 | `CHAR(24)` | `VARCHAR(28)` | `VARCHAR(33)` |

Formula: `body_length + prefix_length + 1` (the `+1` accounts for the underscore separator).

Use `recommendedColumnSize()` to calculate this programmatically:

```php
HybridIdGenerator::recommendedColumnSize('extended', maxPrefixLength: 7);  // 32
HybridIdGenerator::recommendedColumnSize('standard');                       // 20 (no prefix)
```

### Column guard with maxIdLength

Prevent runtime truncation by setting `maxIdLength` in the constructor:

```php
$gen = new HybridIdGenerator(profile: 'extended', maxIdLength: 32);

$gen->generate('billing');   // OK → 32 chars (7 + 1 + 24)
$gen->generate('shipping');  // throws OverflowException → 33 chars exceeds 32
```

### SQL examples

```sql
-- Standard profile (unprefixed)
CREATE TABLE users (
    id CHAR(20) NOT NULL PRIMARY KEY,
    ...
);

-- Compact profile (unprefixed)
CREATE TABLE logs (
    id CHAR(16) NOT NULL PRIMARY KEY,
    ...
);

-- With prefixes: use VARCHAR to accommodate prefix + underscore
CREATE TABLE orders (
    id VARCHAR(29) NOT NULL PRIMARY KEY,  -- up to 8 prefix + 1 underscore + 20 ID
    ...
);
```

**Why this works well with B-tree indexes:**
- Chronological ordering means sequential inserts, reducing page splits
- Smaller than CHAR(36) UUIDs, improving index density and JOIN performance
- Base62 encoding is safe for any column collation

## Choosing a Profile

- **`compact` (16 chars)**: Internal PKs, low-scale apps, storage-constrained systems. ~35.7 bits entropy means 50% collision probability at ~236,000 IDs per millisecond per node. Not recommended for high-throughput multi-node systems.
- **`standard` (20 chars)**: General purpose, recommended default. ~59.5 bits provides comfortable collision resistance for most applications.
- **`extended` (24 chars)**: High-scale, public-facing IDs, when you need more entropy than UUID v7. ~83.4 bits of random entropy.
- **Custom profiles**: Use `registerProfile()` when built-in profiles don't match your requirements.

## Security Considerations

**Not for secrets.** Do NOT use HybridId for security tokens, password resets, API keys, or session tokens. The timestamp is predictable and reduces effective entropy. Use `random_bytes()` with 128+ bits of pure entropy for those.

**Timestamp disclosure.** The first 8 characters encode the creation time to the millisecond. Anyone with a HybridId can extract when it was created and which node generated it. This is inherent to the design (same as UUID v7). Do not use HybridId where creation time must be confidential.

**Validation is not constant-time.** `isValid()` returns early on the first invalid character. If you compare HybridIds in security-sensitive contexts (e.g., authorization), use `hash_equals()` instead of `===` to prevent timing side-channels.

## Clock Drift Protection

Each generator instance maintains a monotonic guard that ensures timestamps never go backward and strictly increment even within the same millisecond. If the system clock moves backward (NTP adjustment), or multiple IDs are generated in the same millisecond, the timestamp increments by 1ms to guarantee strict chronological ordering.

**Timestamp drift under high throughput.** When N IDs are generated within the same millisecond, the last ID's timestamp will be `real_time + (N - 1)` ms. This means `extractDateTime()` may return a time slightly ahead of the actual wall-clock creation time. The drift is negligible in practice (e.g., 1,000 IDs/ms = 1 second of drift) and self-corrects as soon as wall-clock time catches up.

## Concurrency and Limitations

**Per-instance scope.** The monotonic guard is scoped to each `HybridIdGenerator` instance. In PHP-FPM or mod_php, each request creates its own instance with independent state. Two concurrent requests in the same millisecond on the same node rely on the random component for uniqueness.

**Async runtimes.** In long-running processes (Swoole, ReactPHP, Amphp), a shared instance maintains monotonic ordering within the process. The guard works correctly under cooperative scheduling (PHP Fibers), but has no atomicity guarantees under preemptive coroutines.

**Node auto-detection.** The auto-detected node is derived from `gethostname()` and `getmypid()` via `crc32()`, reduced to 3,844 possible values (62²). By the birthday paradox, 50% collision probability is reached at ~74 distinct host:pid pairs. In clustered deployments, always set the node explicitly to guarantee uniqueness.

## Upgrading from v1.x

v2.0.0 replaces the static `HybridId` class with an instance-based `HybridIdGenerator`. There is no backward compatibility layer.

### API changes

| v1.x | v2.0.0 |
|---|---|
| `use HybridId\HybridId` | `use HybridId\HybridIdGenerator` |
| `HybridId::configure(['profile' => 'compact', 'node' => 'A1'])` | `new HybridIdGenerator(profile: 'compact', node: 'A1')` |
| `HybridId::generate()` | `$gen->generate()` |
| `HybridId::compact()` | `$gen->compact()` |
| `HybridId::standard()` | `$gen->standard()` |
| `HybridId::extended()` | `$gen->extended()` |
| `HybridId::configureFromEnv()` | `HybridIdGenerator::fromEnv()` |
| `HybridId::reset()` | Create a new instance |
| `HybridId::entropy()` (no args) | `HybridIdGenerator::entropy('standard')` (required arg) |
| `HybridId::profileConfig()` (no args) | `HybridIdGenerator::profileConfig('standard')` (required arg) |

### Static utilities (unchanged pattern)

These methods remain static and work the same way, just on `HybridIdGenerator`:

```php
HybridIdGenerator::isValid($id);
HybridIdGenerator::detectProfile($id);
HybridIdGenerator::extractTimestamp($id);
HybridIdGenerator::extractDateTime($id);
HybridIdGenerator::extractNode($id);
HybridIdGenerator::profiles();
```

### New in v2.0.0

- **Prefixes**: `$gen->generate('usr')` produces `usr_...`
- **Instance-based API**: Multiple generators with independent state
- **`IdGenerator` interface**: For dependency injection and testing
- **`compare()`**: Chronological sorting with `usort()`
- **Custom profiles**: `registerProfile('ultra', 22)`
- **`fromEnv()`**: Named constructor from environment variables
- **`getProfile()` / `getNode()`**: Instance getters

### New in v2.2.0

- **`bodyLength()`**: Instance method returning body length for the configured profile
- **`validate()`**: Instance method for profile-aware validation with optional prefix matching
- **`parse()`**: Static method to extract all ID components in a single pass
- **`recommendedColumnSize()`**: Static helper for database column sizing
- **`maxIdLength`**: Optional constructor parameter to guard against column overflow

### ID format

The generated ID format is identical between v1.x and v2.0.0. IDs generated by v1.x are fully valid and readable by v2.0.0 utilities.

## Requirements

- PHP >= 8.3 (64-bit)
- No external dependencies

## License

MIT
