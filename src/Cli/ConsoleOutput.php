<?php

declare(strict_types=1);

namespace HybridId\Cli;

/**
 * @internal CLI output to STDOUT/STDERR. Not part of the library's public API.
 */
final class ConsoleOutput implements OutputInterface
{
    public function writeln(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function error(string $message): void
    {
        fwrite(STDERR, "\033[31mError:\033[0m {$message}" . PHP_EOL);
    }
}
