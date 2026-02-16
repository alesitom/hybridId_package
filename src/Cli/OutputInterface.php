<?php

declare(strict_types=1);

namespace HybridId\Cli;

/**
 * @internal CLI output abstraction. Not part of the library's public API.
 */
interface OutputInterface
{
    public function writeln(string $message): void;

    public function error(string $message): void;
}
