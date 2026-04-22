<?php

declare(strict_types=1);

namespace HybridId;

use HybridId\Exception\InvalidIdException;
use HybridId\Exception\Messages;

/**
 * Immutable Value Object representing a parsed HybridId.
 *
 * @since 4.1.0
 */
final class HybridId implements \Stringable, \JsonSerializable
{
    public readonly string $id;
    public readonly ?string $prefix;
    public readonly string $profile;
    public readonly int $timestamp;
    public readonly \DateTimeImmutable $dateTime;
    public readonly ?string $node;

    /**
     * @throws InvalidIdException If the given ID is invalid
     */
    public function __construct(string $id)
    {
        $parsed = HybridIdGenerator::parse($id);

        if (!$parsed['valid']) {
            throw new InvalidIdException(sprintf('%s: "%s"', Messages::GEN_FORMAT_INVALID, $id));
        }

        /** @var string $profile */
        $profile = $parsed['profile'];
        /** @var int $timestamp */
        $timestamp = $parsed['timestamp'];
        /** @var \DateTimeImmutable $dateTime */
        $dateTime = $parsed['datetime'];

        $this->id = $id;
        $this->prefix = $parsed['prefix'];
        $this->profile = $profile;
        $this->timestamp = $timestamp;
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
