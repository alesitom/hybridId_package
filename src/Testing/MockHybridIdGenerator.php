<?php

declare(strict_types=1);

namespace HybridId\Testing;

use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;

/** @since 4.0.0 */
final class MockHybridIdGenerator implements IdGenerator
{
    /** @var list<string> */
    private readonly array $ids;
    private int $cursor = 0;
    private readonly int $bodyLength;

    /**
     * @param list<string> $ids Sequence of IDs to return from generate()
     * @param int $bodyLength Body length to report (default: 20, standard profile)
     */
    public function __construct(array $ids, int $bodyLength = 20)
    {
        if ($ids === []) {
            throw new \InvalidArgumentException('MockHybridIdGenerator requires at least one ID');
        }

        $this->ids = array_values($ids);
        $this->bodyLength = $bodyLength;
    }

    /**
     * Returns the next ID from the sequence.
     *
     * When $prefix is provided, the next ID must already include it
     * (e.g. "usr_abc..."). If it doesn't, an exception is thrown so
     * the developer can fix their mock setup.
     */
    #[\Override]
    public function generate(?string $prefix = null): string
    {
        if ($this->cursor >= count($this->ids)) {
            throw new \OverflowException(
                sprintf(
                    'MockHybridIdGenerator exhausted: all %d IDs have been consumed',
                    count($this->ids),
                ),
            );
        }

        $id = $this->ids[$this->cursor++];

        if ($prefix !== null && !str_starts_with($id, $prefix . '_')) {
            throw new \LogicException(
                sprintf(
                    'MockHybridIdGenerator: generate() called with prefix "%s" but next ID "%s" '
                    . 'does not start with "%s_". Include the prefix in your mock IDs.',
                    $prefix,
                    $id,
                    $prefix,
                ),
            );
        }

        return $id;
    }

    #[\Override]
    public function generateBatch(int $count, ?string $prefix = null): array
    {
        if ($count < 1 || $count > 10_000) {
            throw new \InvalidArgumentException(
                sprintf('Batch count must be between 1 and 10,000, got %d', $count),
            );
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate($prefix);
        }

        return $ids;
    }

    #[\Override]
    public function bodyLength(): int
    {
        return $this->bodyLength;
    }

    #[\Override]
    public function validate(string $id, ?string $expectedPrefix = null): bool
    {
        return HybridIdGenerator::isValid($id)
            && ($expectedPrefix === null || HybridIdGenerator::extractPrefix($id) === $expectedPrefix);
    }

    /**
     * How many IDs remain before exhaustion.
     */
    public function remaining(): int
    {
        return count($this->ids) - $this->cursor;
    }

    /**
     * Reset the cursor to the beginning of the sequence.
     */
    public function reset(): void
    {
        $this->cursor = 0;
    }
}
