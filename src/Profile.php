<?php

declare(strict_types=1);

namespace HybridId;

/** @since 4.0.0 */
enum Profile: string
{
    case Compact = 'compact';
    case Standard = 'standard';
    case Extended = 'extended';

    /**
     * Body length (without prefix) for this profile.
     *
     * Only covers built-in profiles. Custom profiles registered via
     * ProfileRegistry are not represented by enum cases.
     *
     * @since 4.2.0
     */
    public function bodyLength(): int
    {
        return match ($this) {
            self::Compact => 16,
            self::Standard => 20,
            self::Extended => 24,
        };
    }

    /**
     * Full configuration array for this profile.
     *
     * Shortcut for HybridIdGenerator::profileConfig($this).
     *
     * @return array{length: int, node: int, random: int}
     *
     * @since 4.2.0
     */
    public function config(): array
    {
        return HybridIdGenerator::profileConfig($this);
    }
}
