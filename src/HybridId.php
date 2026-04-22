<?php

declare(strict_types=1);

namespace HybridId;

use HybridId\Exception\InvalidIdException;

/**
 * Immutable Value Object representing a parsed HybridId.
 *
 * @since 4.1.0
 */
final class HybridId implements \Stringable, \JsonSerializable
{
    private readonly string $id;
    private readonly ?string $prefix;
    private readonly string $profile;
    private readonly int $timestamp;
    private readonly \DateTimeImmutable $dateTime;
    private readonly ?string $node;

    /**
     * @throws InvalidIdException If the given ID is invalid
     */
    public function __construct(string $id)
    {
        $parsed = HybridIdGenerator::parse($id);

        if (!$parsed['valid']) {
            throw new InvalidIdException(sprintf('Invalid HybridId format: "%s"', $id));
        }

        $this->id = $id;
        $this->prefix = $parsed['prefix'];
        /** @var string $profile */
        $profile = $parsed['profile'];
        $this->profile = $profile;
        /** @var int $timestamp */
        $timestamp = $parsed['timestamp'];
        $this->timestamp = $timestamp;
        /** @var \DateTimeImmutable $dateTime */
        $dateTime = $parsed['datetime'];
        $this->dateTime = $dateTime;
        $this->node = $parsed['node'];
    }

    /**
     * Named constructor for more expressive instantiation.
     *
     * @throws InvalidIdException If the given ID is invalid
     */
    public static function fromString(string $id): self
    {
        return new self($id);
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getDateTime(): \DateTimeImmutable
    {
        return $this->dateTime;
    }

    public function getNode(): ?string
    {
        return $this->node;
    }

    public function toString(): string
    {
        return $this->id;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->id;
    }

    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->id;
    }
}
