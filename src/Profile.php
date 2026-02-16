<?php

declare(strict_types=1);

namespace HybridId;

enum Profile: string
{
    case Compact = 'compact';
    case Standard = 'standard';
    case Extended = 'extended';
}
