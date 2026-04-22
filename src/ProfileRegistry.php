<?php

declare(strict_types=1);

namespace HybridId;

use HybridId\Exception\InvalidProfileException;
use HybridId\Exception\Messages;

/** @since 4.0.0 */
final class ProfileRegistry implements ProfileRegistryInterface
{
    private const array BUILT_IN = [
        'compact'  => ['length' => 16, 'ts' => 8, 'node' => 0, 'random' => 8],
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

    #[\Override]
    public function get(string $name): ?array
    {
        return self::BUILT_IN[$name] ?? $this->custom[$name] ?? null;
    }

    #[\Override]
    public function getByLength(int $length): ?string
    {
        return self::BUILT_IN_LENGTH_MAP[$length] ?? $this->customLengthMap[$length] ?? null;
    }

    #[\Override]
    public function register(string $name, int $random, int $node = 2): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            throw new InvalidProfileException(Messages::PROFILE_NAME_INVALID);
        }

        if ($this->get($name) !== null) {
            throw new InvalidProfileException(
                sprintf(Messages::PROFILE_EXISTS, $name),
            );
        }

        if ($random < 6 || $random > 128) {
            throw new InvalidProfileException(Messages::RANDOM_LENGTH_INVALID);
        }

        if ($node < 0 || $node > 10) {
            throw new InvalidProfileException('Node length must be between 0 and 10');
        }

        $length = 8 + $node + $random;

        $existing = $this->getByLength($length);
        if ($existing !== null) {
            throw new InvalidProfileException(
                sprintf(Messages::LENGTH_CONFLICT, $length, $existing),
            );
        }

        $this->custom[$name] = [
            'length' => $length,
            'ts' => 8,
            'node' => $node,
            'random' => $random,
        ];
        $this->customLengthMap[$length] = $name;
    }

    #[\Override]
    public function all(): array
    {
        return [...array_keys(self::BUILT_IN), ...array_keys($this->custom)];
    }

    #[\Override]
    public function reset(): void
    {
        $this->custom = [];
        $this->customLengthMap = [];
    }
}
