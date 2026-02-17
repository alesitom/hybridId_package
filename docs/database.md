# Database Guide

HybridId-specific database patterns beyond the basics covered in the README.

## Time-Range Queries

Use `minForTimestamp()` and `maxForTimestamp()` to query by creation time using the primary key index directly — no separate `created_at` column needed.

```php
$startMs = strtotime('2026-01-01') * 1000;
$endMs   = strtotime('2026-02-01') * 1000;

$min = HybridIdGenerator::minForTimestamp($startMs);
$max = HybridIdGenerator::maxForTimestamp($endMs);
```

These generate boundary IDs: `min` fills node+random with `0` (lowest base62), `max` fills with `z` (highest base62).

```sql
-- All orders in January 2026, using the clustered PK index
SELECT * FROM orders
WHERE id >= :min AND id <= :max
ORDER BY id;
```

This is faster than filtering on a `created_at` column because it scans the B-tree directly without a secondary index lookup.

### Cursor-based pagination

```sql
SELECT * FROM orders
WHERE id > :last_seen_id
ORDER BY id
LIMIT 20;
```

No offset needed. Efficient at any depth.

### Prefix considerations

Range helpers return unprefixed IDs. If your table stores prefixed IDs, prepend the prefix:

```php
$min = 'ord_' . HybridIdGenerator::minForTimestamp($startMs);
$max = 'ord_' . HybridIdGenerator::maxForTimestamp($endMs);
```

## NoSQL Patterns

### MongoDB

HybridIds sort correctly as strings. Use as `_id` for natural time ordering:

```javascript
db.events.find({
    _id: { $gte: minId, $lte: maxId }
});
```

### DynamoDB

Use HybridId as the **sort key** for time-ordered queries within a partition:

```
Partition key: tenant_id
Sort key: hybrid_id
```

Avoid using HybridId as the partition key directly — the time-sorted prefix creates write hotspots. If you must, consider prefixing with a hash shard.

### Redis

Sorted sets with lexicographic range:

```
ZADD events 0 "0VBFDQz4A1Rtntu09sbf"
ZRANGEBYLEX events "[0VBFDQz4" "[0VBFDQz5"
```

## Migration from UUID

### Approach

1. Add a `hybrid_id` column alongside the existing UUID column
2. Dual-write: generate HybridIds for new records while keeping UUIDs
3. Backfill existing records using `UuidConverter::fromUUIDv4Format()` with original timestamps
4. Switch reads to `hybrid_id`
5. Drop the UUID column

### Backfill

```php
foreach ($records as $record) {
    $hybridId = UuidConverter::fromUUIDv4Format(
        $record->uuid,
        profile: 'standard',
        timestampMs: $record->created_at->getTimestamp() * 1000,
        node: 'A1',
    );
    $record->update(['hybrid_id' => $hybridId]);
}
```

### When NOT to migrate

- External APIs that expect UUIDs — use `UuidConverter` at the boundary instead
- Tables with heavy foreign key dependencies where downtime is unacceptable
- If you only need smaller IDs for new tables, just use HybridId there

### Coexistence

For systems that need both formats, convert at the boundary:

```php
// Store as HybridId internally
$id = $gen->generate('usr');

// Expose as UUID to external consumers
$uuid = UuidConverter::toUUIDv8(HybridIdGenerator::extractPrefix($id)
    ? substr($id, strlen(HybridIdGenerator::extractPrefix($id)) + 1)
    : $id);
```
