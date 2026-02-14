<?php

declare(strict_types=1);

namespace HybridId;

interface IdGenerator
{
    public function generate(?string $prefix = null): string;
}
