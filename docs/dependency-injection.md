# Dependency Injection & Testing

## IdGenerator Interface

Type-hint against `IdGenerator` for testability:

```php
namespace HybridId;

interface IdGenerator
{
    public function generate(?string $prefix = null): string;
    public function generateBatch(int $count, ?string $prefix = null): array;
    public function bodyLength(): int;
    public function validate(string $id, ?string $expectedPrefix = null): bool;
}
```

```php
class OrderService
{
    public function __construct(
        private readonly IdGenerator $idGenerator,
    ) {}

    public function createOrder(array $data): Order
    {
        return new Order($this->idGenerator->generate('ord'), $data);
    }
}
```

## ProfileRegistry Injection

Custom profiles use `ProfileRegistryInterface` instead of the deprecated global `registerProfile()`:

```php
$registry = ProfileRegistry::withDefaults();
$registry->register('medium', 12); // 8ts + 2node + 12rand = 22 chars

$gen = new HybridIdGenerator(
    profile: 'medium',
    node: 'A1',
    registry: $registry,
);
```

This is safe for long-lived processes (Swoole, RoadRunner, Laravel Octane) and multi-tenant environments.

## Framework Integration

### Laravel

```bash
composer require alesitom/hybrid-id-laravel
```

Or manually:

```php
// AppServiceProvider
$this->app->singleton(IdGenerator::class, fn() => HybridIdGenerator::fromEnv());
```

```env
HYBRID_ID_NODE=A1
HYBRID_ID_PROFILE=standard
```

### Symfony

```yaml
services:
    HybridId\IdGenerator:
        class: HybridId\HybridIdGenerator
        factory: ['HybridId\HybridIdGenerator', 'fromEnv']
```

### Doctrine

```bash
composer require alesitom/hybrid-id-doctrine
```

## Testing

### Mock

```php
$mock = $this->createMock(IdGenerator::class);
$mock->method('generate')->willReturn('ord_test123');

$service = new OrderService($mock);
```

### MockHybridIdGenerator

A sequential mock is available in `tests/Testing/`:

```php
use HybridId\Testing\MockHybridIdGenerator;

$mock = new MockHybridIdGenerator(['id_001', 'id_002']);
$mock->generate();   // 'id_001'
$mock->generate();   // 'id_002'
```

### Multi-node

```php
$genA = new HybridIdGenerator(node: 'A1');
$genB = new HybridIdGenerator(node: 'B2');
// IDs from different nodes never collide on the node portion
```
