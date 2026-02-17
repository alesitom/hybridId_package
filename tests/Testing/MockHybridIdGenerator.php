<?php

declare(strict_types=1);

namespace HybridId\Tests\Testing;

use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;

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
     * The $prefix parameter is intentionally ignored — the mock returns exactly
     * the IDs provided in the constructor. Include prefixes in the $ids array
     * if needed.
     */
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

        return $this->ids[$this->cursor++];
    }

    public function generateBatch(int $count, ?string $prefix = null): array
    {
        if ($count < 1 || $count > 100_000) {
            throw new \InvalidArgumentException(
                sprintf('Batch count must be between 1 and 100,000, got %d', $count),
            );
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate($prefix);
        }

        return $ids;
    }

    public function bodyLength(): int
    {
        return $this->bodyLength;
    }

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
