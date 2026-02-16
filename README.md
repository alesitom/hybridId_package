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
| Random entropy | 122 bits | 74 bits | 80 bits | 12 bits | 47.6 - 83.4+ bits |
| Dependencies | None | None | Library | Library | None |

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
```

## Profiles

Three built-in profiles with different size/entropy tradeoffs:

| Profile | Length | Structure | Random entropy | vs UUID v7 (74 bits) |
|---|---|---|---|---|
| `compact` | 16 | 8ts + 8rand | 47.6 bits | Lower |
| `standard` | 20 | 8ts + 2node + 10rand | 59.5 bits | Comparable |
| `extended` | 24 | 8ts + 2node + 14rand | 83.4 bits | Higher |

**Structure breakdown:**

```
Standard / Extended:

0VBFDQz4 A1 Rtntu09sbf
|______| |_| |_________|
   ts   node   random

Compact (no node):

0VBFDQz4 xK9mLp2w
|______| |________|
   ts      random
```

- **ts** (8 chars): Millisecond timestamp in base62. Enables chronological sorting. Covers ~6,920 years from epoch.
- **node** (2 chars, standard/extended only): Server/process identifier. Prevents cross-node collisions. Compact omits this to maximize entropy within 16 characters.
- **rand** (variable): Cryptographically secure random bytes. Prevents same-millisecond collisions.

## Creating a Generator

### Via constructor

```php
use HybridId\HybridIdGenerator;

// Standard profile with explicit node (recommended for production)
$gen = new HybridIdGenerator(node: 'A1');

// Explicit profile and node
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');

// Compact profile — no node needed (compact has no node component)
$gen = new HybridIdGenerator(profile: 'compact');

// With column size guard (throws OverflowException if prefix + body exceeds limit)
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1', maxIdLength: 32);
```

### Node requirement (production safety)

By default, standard and extended profiles **require** an explicit node. This prevents accidental use of auto-detected nodes in production, where collisions are likely in clustered deployments.

```php
// Throws NodeRequiredException — no node provided for standard profile
$gen = new HybridIdGenerator();

// OK — explicit node
$gen = new HybridIdGenerator(node: 'A1');

// OK — compact profile has no node component, so no node is needed
$gen = new HybridIdGenerator(profile: 'compact');

// Opt out for local development / testing (auto-detects a random node)
$gen = new HybridIdGenerator(requireExplicitNode: false);
```

### Via environment variables

```php
$gen = HybridIdGenerator::fromEnv();
```

Reads from:

```env
HYBRID_ID_PROFILE=standard
HYBRID_ID_NODE=A1
HYBRID_ID_REQUIRE_NODE=1    # Default is true; set to 0 to disable
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

When `requireExplicitNode` is disabled and no node is provided, the generator creates a random 2-char node using `random_bytes()`. This yields 3,844 possible values (62²) per instance and is **non-deterministic** — each new instance gets a different random node.

This is intended as a convenience for local development and testing only. For production deployments, always set an explicit node to guarantee uniqueness across instances.

## Generating IDs

```php
$gen = new HybridIdGenerator(node: 'A1');

// Using the instance's configured profile (default: standard)
$id = $gen->generate();       // 0VBFDQz4A1Rtntu09sbf

// Explicit profile methods
$id = $gen->compact();        // 0VBFDQz4xK9mLp2w       (no node in compact)
$id = $gen->standard();       // 0VBFDQz5A1S0PQgr0sbf
$id = $gen->extended();       // 0VBFDQz6A1xiF0G9pBKVwwn2
```

## Prefixes

Stripe-style prefixes make IDs self-documenting. Pass an optional prefix to any generation method:

```php
$gen = new HybridIdGenerator(node: 'A1');

$id = $gen->generate('usr');   // usr_0VBFDQz4A1Rtntu09sbf
$id = $gen->generate('ord');   // ord_0VBFDQz5A1xiF0G9pBKV
$id = $gen->compact('log');    // log_0VBFDQz6xK9mLp2w
$id = $gen->extended('txn');   // txn_0VBFDQz7A1pBKVwwn2xiF0
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
$logIds  = new HybridIdGenerator(profile: 'compact');  // compact has no node

$userId = $userIds->generate('usr');  // usr_... (24 char body)
$logId  = $logIds->generate('log');   // log_... (16 char body, no node)
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
$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');

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

// Compact IDs have no node
$compact = $gen->compact();
HybridIdGenerator::extractNode($compact);  // null
```

## Parsing

Extract all components in a single call with `parse()`:

```php
$result = HybridIdGenerator::parse('usr_0VB0Td2uA1mcw1hoy5Kg8mR');
// [
//     'valid'     => true,
//     'prefix'    => 'usr',
//     'profile'   => 'extended',
//     'body'      => '0VB0Td2uA1mcw1hoy5Kg8mR',
//     'timestamp' => 1708012345678,
//     'datetime'  => DateTimeImmutable,
//     'node'      => 'A1',
//     'random'    => 'mcw1hoy5Kg8mR',
// ]
//
// Compact IDs return null for node:
// HybridIdGenerator::parse($compactId)['node']  → null

// Invalid IDs return partial data with valid => false
$result = HybridIdGenerator::parse('not_valid');
// ['valid' => false, 'prefix' => 'not', 'body' => 'valid']
```

## Sorting

Compare IDs chronologically with `compare()`, compatible with `usort()`:

```php
use HybridId\HybridIdGenerator;

$gen = new HybridIdGenerator(node: 'A1');
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

HybridIdGenerator::entropy('compact');       // 47.6
HybridIdGenerator::entropy('standard');      // 59.5
HybridIdGenerator::entropy('extended');      // 83.4

HybridIdGenerator::profiles();               // ['compact', 'standard', 'extended']

HybridIdGenerator::profileConfig('compact');
// ['length' => 16, 'ts' => 8, 'node' => 0, 'random' => 8]

$gen = new HybridIdGenerator(profile: 'extended', node: 'A1');
$gen->getProfile();      // "extended"
$gen->getNode();         // "A1"
$gen->bodyLength();      // 24
$gen->getMaxIdLength();  // null (no limit set)
```

## Custom Profiles

Register profiles with custom random lengths. Custom profiles always include timestamp (8) + node (2) + your random portion:

```php
use HybridId\HybridIdGenerator;

// Register a 32-char profile: 8ts + 2node + 22random
HybridIdGenerator::registerProfile('ultra', 22);

$gen = new HybridIdGenerator(profile: 'ultra', node: 'A1');
$id = $gen->generate('txn');

strlen($id);                                    // 36 (3 prefix + 1 underscore + 32)
HybridIdGenerator::detectProfile($id);          // "ultra"
HybridIdGenerator::entropy('ultra');             // 130.9

// Register an 18-char profile: 8ts + 2node + 8random
HybridIdGenerator::registerProfile('tiny', 8);
```

Constraints:
- Profile name must be lowercase alphanumeric, starting with a letter
- Random length must be between 6 and 128
- Total length must not conflict with an existing profile

**Important:** Call `registerProfile()` during application bootstrap, before creating any generator instances. The profile registry is global (static), and mutations after construction will not affect existing instances' cached configuration.

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
    public function bodyLength(): int;
    public function validate(string $id, ?string $expectedPrefix = null): bool;
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
# Inspect a standard ID (shows node)
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

# Inspect a compact ID (no node)
./vendor/bin/hybrid-id inspect 0VBFDQz4xK9mLp2w

#   ID:         0VBFDQz4xK9mLp2w
#   Profile:    compact (16 chars)
#   Timestamp:  1771109611324
#   DateTime:   2026-02-14 22:53:31.324
#   Random:     xK9mLp2w
#   Entropy:    47.6 bits
#   Valid:      yes
```

```bash
# Show all profiles
./vendor/bin/hybrid-id profiles

#   Profile     Length   Structure              Random bits   vs UUID v7
#   -------     ------   ---------              -----------   ----------
#   compact     16       8ts + 8rand            47.6 bits     < UUID v7
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

### Collation (important for MySQL/MariaDB)

Base62 encoding uses both uppercase and lowercase characters (`A` != `a`). You **must** use a binary or case-sensitive collation for HybridId columns. The default `utf8mb4_0900_ai_ci` is case-insensitive and will silently break uniqueness constraints and sort order.

```sql
-- Recommended: ascii_bin for pure base62 IDs
CREATE TABLE users (
    id CHAR(20) COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ...
);

-- Alternative: utf8mb4_bin if your schema requires uniform collation
CREATE TABLE orders (
    id VARCHAR(29) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    ...
);
```

PostgreSQL and SQLite default to case-sensitive comparison, so no special collation is needed there.

### SQL examples

```sql
-- Standard profile (unprefixed)
CREATE TABLE users (
    id CHAR(20) COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ...
);

-- Compact profile (unprefixed)
CREATE TABLE logs (
    id CHAR(16) COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ...
);

-- With prefixes: use VARCHAR to accommodate prefix + underscore
CREATE TABLE orders (
    id VARCHAR(29) COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ...
);
```

### Time-range queries

Use `minForTimestamp()` and `maxForTimestamp()` to query by time range without storing a separate timestamp column:

```php
$startMs = (int) ($startDate->format('U.v') * 1000);
$endMs   = (int) ($endDate->format('U.v') * 1000);

$min = HybridIdGenerator::minForTimestamp($startMs, 'standard');
$max = HybridIdGenerator::maxForTimestamp($endMs, 'standard');

// SELECT * FROM orders WHERE id BETWEEN ? AND ?
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id BETWEEN ? AND ?');
$stmt->execute([$min, $max]);
```

These return synthetic boundary IDs (all-zero or all-max node+random) suitable for inclusive `BETWEEN` queries. They leverage the B-tree index directly — no table scan needed.

**Why this works well with B-tree indexes:**
- Chronological ordering means sequential inserts, reducing page splits
- Smaller than CHAR(36) UUIDs, improving index density and JOIN performance
- Binary collation ensures correct lexicographic sort order

## Choosing a Profile

- **`compact` (16 chars)**: Internal PKs, low-scale apps, storage-constrained systems. No node component — all 8 non-timestamp characters are random (~47.6 bits entropy). 50% collision probability at ~18.9 million IDs per millisecond globally. Not recommended for high-throughput distributed systems where node isolation is critical.
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

**Forward clock jumps.** If the system clock jumps far into the future (e.g. NTP correction) and then corrects back, the monotonic guard holds the inflated timestamp until wall-clock time catches up. This preserves ordering guarantees — no ID will ever have a lower timestamp than a previously generated one — but timestamps may appear ahead of real time during the catch-up window. This is a deliberate trade-off: ordering is always preserved at the cost of temporary timestamp inflation.

## Concurrency and Limitations

**Per-instance scope.** The monotonic guard is scoped to each `HybridIdGenerator` instance. In PHP-FPM or mod_php, each request creates its own instance with independent state. Two concurrent requests in the same millisecond on the same node rely on the random component for uniqueness.

**Async runtimes.** In long-running processes (Swoole, ReactPHP, Amphp), a shared instance maintains monotonic ordering within the process. The guard works correctly under cooperative scheduling (PHP Fibers), but has no atomicity guarantees under preemptive coroutines.

**Node auto-detection.** When `requireExplicitNode` is disabled, the auto-detected node is a random 2-char identifier from `random_bytes()`, yielding 3,844 possible values (62²). Each new instance gets a different random node. In clustered deployments, always set the node explicitly to guarantee uniqueness — the default `requireExplicitNode: true` enforces this for standard and extended profiles.

## Upgrading

### From v3.x to v4.0.0

v4.0.0 contains breaking changes to improve entropy and production safety.

**1. Compact profile no longer includes a node component.**

The compact profile structure changed from `8ts + 2node + 6rand` (35.7 bits entropy) to `8ts + 8rand` (47.6 bits entropy). The total length remains 16 characters.

```php
// v3: compact IDs had a node
HybridIdGenerator::extractNode($compactId); // "A1"

// v4: compact IDs have no node
HybridIdGenerator::extractNode($compactId); // null
HybridIdGenerator::parse($compactId)['node']; // null
```

If you have existing compact IDs in your database, they remain valid — `isValid()` and `detectProfile()` still work based on body length. However, `extractNode()` now returns `null` for all compact IDs (including legacy ones that did contain a node).

**2. `requireExplicitNode` now defaults to `true`.**

Standard and extended profiles throw `NodeRequiredException` if no node is provided. This prevents accidental auto-detection in production.

```php
// v3: worked fine, auto-detected node
$gen = new HybridIdGenerator();

// v4: throws NodeRequiredException
$gen = new HybridIdGenerator();

// v4: provide an explicit node
$gen = new HybridIdGenerator(node: 'A1');

// v4: or opt out for local dev/testing
$gen = new HybridIdGenerator(requireExplicitNode: false);
```

The `HYBRID_ID_REQUIRE_NODE` env var now defaults to `true` when not set. Set it to `0` to disable.

**3. `autoDetectNode()` now uses `random_bytes()` instead of `crc32(hostname:pid)`.**

The auto-detected node is no longer deterministic. Each instance gets a different random node. This eliminates the collision weakness of the old `crc32` approach but means the same process may generate different nodes across restarts. This reinforces that auto-detection is for development only.

**4. `decodeBase62()` overflow detection fixed.**

The internal `decodeBase62()` method now uses arithmetic bounds checking instead of the unreliable `is_float()` check. This prevents silent overflow on values exceeding `PHP_INT_MAX`.

### From v2.x to v3.0.0

**Breaking change:** The `IdGenerator` interface now requires two additional methods:

```php
interface IdGenerator
{
    public function generate(?string $prefix = null): string;
    public function bodyLength(): int;     // NEW in v3.0.0
    public function validate(string $id, ?string $expectedPrefix = null): bool;  // NEW in v3.0.0
}
```

If you have custom implementations of `IdGenerator`, add both methods:
- `bodyLength()` — return your ID's body length (without prefix)
- `validate()` — check format against your generator's rules

`HybridIdGenerator` already implements both since v2.2.0. No changes needed if you only use `HybridIdGenerator` directly.

**CLI refactored** to `HybridId\Cli\Application`. The CLI interface (commands, flags, output) is unchanged. If you were `require`-ing `bin/hybrid-id` directly, switch to the `Application` class.

### New in v3.0.0

- **`IdGenerator` interface** expanded with `bodyLength()` and `validate()`
- **CLI refactored** to testable OOP architecture (`HybridId\Cli\Application`)
- **CLI exit codes** fixed: errors now return exit code 1 (previously 0)
- **CLI-only guard**: `bin/hybrid-id` rejects non-CLI SAPI execution
- **`--count` validation**: now uses `filter_var(FILTER_VALIDATE_INT)` instead of `(int)` cast

### From v1.x to v3.0.0

v1.x used a static `HybridId` class. v3.0.0 uses instance-based `HybridIdGenerator`:

| v1.x | v3.0.0 |
|---|---|
| `use HybridId\HybridId` | `use HybridId\HybridIdGenerator` |
| `HybridId::configure([...])` | `new HybridIdGenerator(profile: '...', node: '...')` |
| `HybridId::generate()` | `$gen->generate()` |
| `HybridId::compact()` | `$gen->compact()` |
| `HybridId::standard()` | `$gen->standard()` |
| `HybridId::extended()` | `$gen->extended()` |
| `HybridId::configureFromEnv()` | `HybridIdGenerator::fromEnv()` |
| `HybridId::reset()` | Create a new instance |
| `HybridId::entropy()` (no args) | `HybridIdGenerator::entropy('standard')` (required arg) |
| No validation | `$gen->validate($id, 'usr')` |
| No body length | `$gen->bodyLength()` |

Static utilities remain available:

```php
HybridIdGenerator::isValid($id);
HybridIdGenerator::detectProfile($id);
HybridIdGenerator::extractTimestamp($id);
HybridIdGenerator::extractDateTime($id);
HybridIdGenerator::extractNode($id);
HybridIdGenerator::parse($id);
HybridIdGenerator::recommendedColumnSize('standard', 3);
```

### ID format compatibility

Standard and extended ID formats are identical across all versions. IDs generated by v1.x/v2.x/v3.x are fully valid and readable by v4.0.0 utilities.

**Note:** Compact IDs generated by v4.0.0 have a different internal structure (no node) compared to v3.x compact IDs, but both are 16 characters and pass validation. The only observable difference is that `extractNode()` returns `null` for all compact IDs in v4.0.0.

## Migrating from UUID

If you're replacing UUID v4/v7 columns with HybridId:

1. **Add a new column** alongside the existing UUID column:
   ```sql
   ALTER TABLE users ADD COLUMN hid CHAR(20) COLLATE ascii_bin DEFAULT NULL;
   ```

2. **Dual-write** in your application: generate both UUID and HybridId for new rows while backfilling old rows.

3. **Backfill** existing rows with new HybridIds. Old rows will get current timestamps — if chronological ordering of legacy data matters, preserve the UUID column as a reference.

4. **Switch reads** to the new column, drop the old UUID column and its index.

**When NOT to migrate:** if the UUID is exposed in external APIs or contracts (URLs, webhooks, partner integrations), changing it requires coordinating with consumers. In that case, keep the UUID as the public identifier and add HybridId as an internal-only column.

## NoSQL Usage

HybridId's timestamp-first layout provides excellent chronological ordering but can create write hotspots when used as a **partition key** in distributed NoSQL systems (DynamoDB, Cassandra, ScyllaDB). Recent timestamps concentrate all writes on the same partition.

**Recommended pattern:** use HybridId as a **sort key**, not a partition key.

```
DynamoDB example:
  PK: user_id (or entity type hash)
  SK: usr_0VBFDQz4CYRtntu09sbf (HybridId)

Cassandra example:
  PRIMARY KEY ((entity_type), hybrid_id)
  -- entity_type distributes writes, hybrid_id sorts within partition
```

When HybridId is safe as a partition key: low write throughput (< 1,000 writes/second) or systems with automatic partition splitting (e.g., DynamoDB adaptive capacity).

## Design Decisions

### Why no version byte in the ID

Every character matters in a 16-24 char ID. Embedding a format version byte would reduce the already limited random entropy or require a longer ID. Instead:

- **Profile detection by length** serves as implicit versioning — each profile has a unique total length
- If a future layout change is needed, a new profile name handles it without touching existing IDs
- Existing IDs remain valid forever — the format is append-only by design

## Framework Integrations

| Package | Framework | Install |
|---|---|---|
| [hybrid-id-laravel](https://github.com/alesitom/hybrid-id-laravel) | Laravel 11/12 | `composer require alesitom/hybrid-id-laravel` |
| [hybrid-id-doctrine](https://github.com/alesitom/hybrid-id-doctrine) | Doctrine DBAL 4 / ORM 3 | `composer require alesitom/hybrid-id-doctrine` |

### Laravel

Eloquent trait with auto-generation and Stripe-style prefixes:

```php
use HybridId\Laravel\HasHybridId;

class Order extends Model
{
    use HasHybridId;
    protected static string $idPrefix = 'ord';
}

$order = Order::create(['total' => 99.90]);
$order->id;  // ord_0VBFDQz4CYRtntu09sbf
```

### Doctrine

DBAL type and ORM ID generator:

```php
use HybridId\Doctrine\HybridIdGenerator;

#[ORM\Entity]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'hybrid_id', length: 29)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: HybridIdGenerator::class)]
    private string $id;

    public static function hybridIdPrefix(): string
    {
        return 'ord';
    }
}
```

## Requirements

- PHP 8.3, 8.4, or 8.5 (64-bit)
- Tested on all three versions via CI
- No external dependencies

## License

MIT
