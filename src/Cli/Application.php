<?php

declare(strict_types=1);

namespace HybridId\Cli;

use HybridId\HybridIdGenerator;

/**
 * @internal CLI application. Not part of the library's public API.
 */
final class Application
{
    private OutputInterface $output;

    public function __construct(?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput();
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        return match ($command) {
            'generate' => $this->commandGenerate(array_slice($argv, 2)),
            'inspect'  => $this->commandInspect(array_slice($argv, 2)),
            'profiles' => $this->commandProfiles(),
            'help', '--help', '-h' => $this->commandHelp(),
            default => $this->commandHelp(unknown: $command),
        };
    }

    // -------------------------------------------------------------------------
    // Commands
    // -------------------------------------------------------------------------

    private function commandGenerate(array $args): int
    {
        $profile = 'standard';
        $count = 1;
        $node = null;
        $prefix = null;

        for ($i = 0, $len = count($args); $i < $len; $i++) {
            switch ($args[$i]) {
                case '-p':
                case '--profile':
                    if (!isset($args[$i + 1])) {
                        $this->output->error('Missing value for --profile');
                        return 1;
                    }
                    $profile = $args[++$i];
                    break;
                case '-n':
                case '--count':
                    if (!isset($args[$i + 1])) {
                        $this->output->error('Missing value for --count');
                        return 1;
                    }
                    $raw = filter_var($args[++$i], FILTER_VALIDATE_INT);
                    if ($raw === false) {
                        $this->output->error('Count must be a valid integer');
                        return 1;
                    }
                    $count = $raw;
                    break;
                case '--node':
                    if (!isset($args[$i + 1])) {
                        $this->output->error('Missing value for --node');
                        return 1;
                    }
                    $node = $args[++$i];
                    break;
                case '--prefix':
                    if (!isset($args[$i + 1])) {
                        $this->output->error('Missing value for --prefix');
                        return 1;
                    }
                    $prefix = $args[++$i];
                    break;
                default:
                    if (str_starts_with($args[$i], '-')) {
                        $this->output->error('Unknown option: ' . self::sanitize($args[$i]));
                        return 1;
                    }
                    break;
            }
        }

        if ($count < 1) {
            $this->output->error('Count must be a positive integer');
            return 1;
        }
        if ($count > 100000) {
            $this->output->error('Count must not exceed 100,000');
            return 1;
        }

        try {
            $gen = new HybridIdGenerator(profile: $profile, node: $node);
        } catch (\InvalidArgumentException $e) {
            $this->output->error(self::sanitize($e->getMessage()));
            return 1;
        }

        for ($i = 0; $i < $count; $i++) {
            try {
                $this->output->writeln($gen->generate($prefix));
            } catch (\InvalidArgumentException $e) {
                $this->output->error(self::sanitize($e->getMessage()));
                return 1;
            }
        }

        return 0;
    }

    private function commandInspect(array $args): int
    {
        $id = $args[0] ?? null;

        if ($id === null || $id === '') {
            $this->output->error('Usage: hybrid-id inspect <id>');
            return 1;
        }

        $id = self::sanitize($id);
        $profile = HybridIdGenerator::detectProfile($id);

        if ($profile === null) {
            $this->output->error('Invalid HybridId: ' . $id);
            return 1;
        }

        $prefix = HybridIdGenerator::extractPrefix($id);
        $config = HybridIdGenerator::profileConfig($profile);
        $timestamp = HybridIdGenerator::extractTimestamp($id);
        $datetime = HybridIdGenerator::extractDateTime($id);
        $node = HybridIdGenerator::extractNode($id);
        $rawId = $prefix !== null ? substr($id, strlen($prefix) + 1) : $id;
        $random = substr($rawId, 10);
        $entropy = HybridIdGenerator::entropy($profile);

        $this->output->writeln('');
        $this->output->writeln("  ID:         {$id}");
        if ($prefix !== null) {
            $this->output->writeln("  Prefix:     {$prefix}");
        }
        $this->output->writeln("  Profile:    {$profile} ({$config['length']} chars)");
        $this->output->writeln("  Timestamp:  {$timestamp}");
        $this->output->writeln("  DateTime:   {$datetime->format('Y-m-d H:i:s.v')}");
        $this->output->writeln("  Node:       {$node}");
        $this->output->writeln("  Random:     {$random}");
        $this->output->writeln("  Entropy:    {$entropy} bits");
        $this->output->writeln("  Valid:      yes");
        $this->output->writeln('');

        return 0;
    }

    private function commandProfiles(): int
    {
        $this->output->writeln('');
        $this->output->writeln('  Profile     Length   Structure              Random bits   vs UUID v7');
        $this->output->writeln('  -------     ------   ---------              -----------   ----------');

        $comparisons = [
            'compact'  => '< UUID v7',
            'standard' => '~ UUID v7',
            'extended' => '> UUID v7',
        ];

        foreach (HybridIdGenerator::profiles() as $name) {
            $config = HybridIdGenerator::profileConfig($name);
            $entropy = HybridIdGenerator::entropy($name);
            $structure = "{$config['ts']}ts + {$config['node']}node + {$config['random']}rand";
            $cmp = $comparisons[$name] ?? 'custom';

            $this->output->writeln(sprintf(
                '  %-10s  %-7d  %-21s  %-12s  %s',
                $name,
                $config['length'],
                $structure,
                "{$entropy} bits",
                $cmp,
            ));
        }

        $this->output->writeln('');

        return 0;
    }

    private function commandHelp(?string $unknown = null): int
    {
        if ($unknown !== null) {
            $this->output->error('Unknown command: ' . self::sanitize($unknown));
        }

        $this->output->writeln('HybridId - Compact, time-sortable unique ID generator');
        $this->output->writeln('');
        $this->output->writeln('Usage:');
        $this->output->writeln('  hybrid-id generate [options]    Generate one or more IDs');
        $this->output->writeln('  hybrid-id inspect <id>          Inspect an existing ID');
        $this->output->writeln('  hybrid-id profiles              Show available profiles');
        $this->output->writeln('  hybrid-id help                  Show this help');
        $this->output->writeln('');
        $this->output->writeln('Generate options:');
        $this->output->writeln('  -p, --profile <name>   Profile: compact (16), standard (20), extended (24)');
        $this->output->writeln('  -n, --count <number>   Number of IDs to generate (default: 1)');
        $this->output->writeln('  --node <XX>            Node identifier (2 base62 chars)');
        $this->output->writeln('  --prefix <name>        Prefix for self-documenting IDs (e.g., usr, ord)');
        $this->output->writeln('');
        $this->output->writeln('Examples:');
        $this->output->writeln('  hybrid-id generate');
        $this->output->writeln('  hybrid-id generate -p compact -n 10');
        $this->output->writeln('  hybrid-id generate -p extended --node A1');
        $this->output->writeln('  hybrid-id generate --prefix usr');
        $this->output->writeln('  hybrid-id inspect usr_0A1b2C3dX9YyZzWwQq12');
        $this->output->writeln('');

        return $unknown !== null ? 1 : 0;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function sanitize(string $input): string
    {
        return preg_replace('/[^\x20-\x7e]/', '', $input);
    }
}
