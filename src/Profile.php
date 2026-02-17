<?php

declare(strict_types=1);

namespace HybridId;

/** @since 4.0.0 */
enum Profile: string
{
    case Compact = 'compact';
    case Standard = 'standard';
    case Extended = 'extended';
}
