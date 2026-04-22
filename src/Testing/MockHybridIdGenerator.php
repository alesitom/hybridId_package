<?php

declare(strict_types=1);

namespace HybridId\Testing;

use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;
use HybridId\Exception\Messages;

/** @since 4.0.0 */
final class MockHybridIdGenerator implements IdGenerator
{
    /** @var list<string> */
    private readonly array $ids;
    private int $cursor = 0;

    /**
     * @param list<string> $ids Sequence of IDs to return from generate()
     * @param int $bodyLength Body length to report (default: 20, standard profile)
     * @param (\Closure(?string): string)|null $callback Internal — use withCallback() factory instead
     */
    public function __construct(
        array $ids = [],
        private readonly int $bodyLength = 20,
        private readonly ?\Closure $callback = null,
    ) {
        if ($this->callback === null && $ids === []) {
            throw new \InvalidArgumentException(Messages::MOCK_EMPTY);
        }

        $this->ids = array_values($ids);
    }

    /**
     * Create a mock that generates IDs dynamically via a callback.
     *
     * The callback receives the prefix (or null) and must return a full ID string.
     * When a prefix is requested, the returned ID must start with "{$prefix}_".
     * Unlike the sequential constructor, this mock never exhausts.
     *
     * @param \Closure(?string): string $callback
     *
     * @since 4.2.0
     */
    public static function withCallback(\Closure $callback, int $bodyLength = 20): self
    {
        return new self([], $bodyLength, $callback);
    }

    /**
     * Returns the next ID from the sequence, or invokes the callback.
     *
     * When $prefix is provided, the returned ID must start with "{$prefix}_".
     * This is enforced in both sequential and callback mode.
     */
    #[\Override]
    public function generate(?string $prefix = null): string
    {
        $id = $this->callback !== null
            ? (string) ($this->callback)($prefix)
            : $this->nextSequentialId();

        if ($prefix !== null && !str_starts_with($id, $prefix . '_')) {
            throw new \LogicException(
                sprintf(
                    Messages::MOCK_PREFIX_MISMATCH,
                    $prefix,
                    $id,
                    $prefix,
                    $this->callback !== null
                        ? 'Ensure your callback returns prefixed IDs when a prefix is requested.'
                        : 'Include the prefix in your mock IDs.',
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
                sprintf(Messages::MOCK_BATCH_LIMIT, $count),
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
     *
     * Returns PHP_INT_MAX in callback mode (never exhausts).
     */
    public function remaining(): int
    {
        if ($this->callback !== null) {
            return PHP_INT_MAX;
        }

        return count($this->ids) - $this->cursor;
    }

    /**
     * Reset the cursor to the beginning of the sequence.
     *
     * No-op in callback mode.
     */
    public function reset(): void
    {
        if ($this->callback !== null) {
            return;
        }

        $this->cursor = 0;
    }

    private function nextSequentialId(): string
    {
        if ($this->cursor >= count($this->ids)) {
            throw new \OverflowException(
                sprintf(
                    Messages::MOCK_EXHAUSTED,
                    count($this->ids),
                ),
            );
        }

        return $this->ids[$this->cursor++];
    }
}
