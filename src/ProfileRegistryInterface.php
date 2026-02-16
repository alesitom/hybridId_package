<?php

declare(strict_types=1);

namespace HybridId;

interface ProfileRegistryInterface
{
    /**
     * Get profile configuration by name.
     * @return array{length: int, ts: int, node: int, random: int}|null
     */
    public function get(string $name): ?array;

    /**
     * Get profile name by body length.
     */
    public function getByLength(int $length): ?string;

    /**
     * Register a custom profile.
     * @param int<6, 128> $random Number of random characters
     */
    public function register(string $name, int $random): void;

    /**
     * Get all profile names.
     * @return list<string>
     */
    public function all(): array;

    /**
     * Remove all custom profiles, keeping only built-in profiles.
     */
    public function reset(): void;
}
