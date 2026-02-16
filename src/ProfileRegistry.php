<?php

declare(strict_types=1);

namespace HybridId;

use HybridId\Exception\InvalidProfileException;

final class ProfileRegistry implements ProfileRegistryInterface
{
    private const array BUILT_IN = [
        'compact'  => ['length' => 16, 'ts' => 8, 'node' => 2, 'random' => 6],
        'standard' => ['length' => 20, 'ts' => 8, 'node' => 2, 'random' => 10],
        'extended' => ['length' => 24, 'ts' => 8, 'node' => 2, 'random' => 14],
    ];

    private const array BUILT_IN_LENGTH_MAP = [
        16 => 'compact',
        20 => 'standard',
        24 => 'extended',
    ];

    /** @var array<string, array{length: int, ts: int, node: int, random: int}> */
    private array $custom = [];

    /** @var array<int, string> */
    private array $customLengthMap = [];

    public static function withDefaults(): self
    {
        return new self();
    }

    public function get(string $name): ?array
    {
        return self::BUILT_IN[$name] ?? $this->custom[$name] ?? null;
    }

    public function getByLength(int $length): ?string
    {
        return self::BUILT_IN_LENGTH_MAP[$length] ?? $this->customLengthMap[$length] ?? null;
    }

    public function register(string $name, int $random): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            throw new InvalidProfileException('Profile name must be lowercase alphanumeric, starting with a letter');
        }

        if ($this->get($name) !== null) {
            throw new InvalidProfileException(
                sprintf('Profile "%s" already exists', $name),
            );
        }

        if ($random < 6 || $random > 128) {
            throw new InvalidProfileException('Random length must be between 6 and 128');
        }

        $length = 8 + 2 + $random;

        $existing = $this->getByLength($length);
        if ($existing !== null) {
            throw new InvalidProfileException(
                sprintf('Length %d conflicts with existing profile "%s"', $length, $existing),
            );
        }

        $this->custom[$name] = [
            'length' => $length,
            'ts' => 8,
            'node' => 2,
            'random' => $random,
        ];
        $this->customLengthMap[$length] = $name;
    }

    public function all(): array
    {
        return [...array_keys(self::BUILT_IN), ...array_keys($this->custom)];
    }

    public function reset(): void
    {
        $this->custom = [];
        $this->customLengthMap = [];
    }
}
