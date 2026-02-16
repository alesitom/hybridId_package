<?php

declare(strict_types=1);

namespace HybridId\Cli;

/**
 * @internal Buffered output for testing. Not part of the library's public API.
 */
final class BufferedOutput implements OutputInterface
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errors = [];

    public function writeln(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    /** @return list<string> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getOutput(): string
    {
        return implode(PHP_EOL, $this->lines);
    }
}
