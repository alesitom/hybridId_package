<?php

declare(strict_types=1);

namespace HybridId;

interface IdGenerator
{
    public function generate(?string $prefix = null): string;

    /**
     * Generate multiple IDs in a single call with guaranteed monotonic ordering.
     *
     * @param int<1, 10000> $count Number of IDs to generate
     * @param string|null $prefix Optional Stripe-style prefix
     * @return list<string> Array of unique, monotonically ordered IDs
     *
     * @since 4.0.0
     */
    public function generateBatch(int $count, ?string $prefix = null): array;

    /**
     * Get the body length (without prefix) for this generator's configuration.
     *
     * @since 3.0.0
     */
    public function bodyLength(): int;

    /**
     * Validate that an ID matches this generator's expected format.
     *
     * This is a format check, not an authorization mechanism. Do not use
     * validate() as a security gate for access control decisions.
     *
     * @param string $id The ID to validate
     * @param string|null $expectedPrefix When provided, the ID's prefix must match exactly
     *
     * @since 3.0.0
     */
    public function validate(string $id, ?string $expectedPrefix = null): bool;
}
