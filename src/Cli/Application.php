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
    private bool $json = false;

    public function __construct(?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $jsonPos = array_search('--json', $argv, true);
        if ($jsonPos !== false) {
            $this->json = true;
            unset($argv[$jsonPos]);
            $argv = array_values($argv);
        }

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

    /**
     * @param list<string> $args
     */
    private function commandGenerate(array $args): int
    {
        $profile = 'standard';
        $count = 1;
        $node = null;
        $prefix = null;
        $blind = false;

        for ($i = 0, $len = count($args); $i < $len; $i++) {
            switch ($args[$i]) {
                case '-p':
                case '--profile':
                    if (!isset($args[$i + 1])) {
                        return $this->emitError('Missing value for --profile');
                    }
                    $profile = $args[++$i];
                    break;
                case '-n':
                case '--count':
                    if (!isset($args[$i + 1])) {
                        return $this->emitError('Missing value for --count');
                    }
                    $raw = filter_var($args[++$i], FILTER_VALIDATE_INT);
                    if ($raw === false) {
                        return $this->emitError('Count must be a valid integer');
                    }
                    $count = $raw;
                    break;
                case '--node':
                    if (!isset($args[$i + 1])) {
                        return $this->emitError('Missing value for --node');
                    }
                    $node = $args[++$i];
                    break;
                case '--prefix':
                    if (!isset($args[$i + 1])) {
                        return $this->emitError('Missing value for --prefix');
                    }
                    $prefix = $args[++$i];
                    break;
                case '--blind':
                    $blind = true;
                    break;
                default:
                    $arg = (string) $args[$i];
                    $msg = str_starts_with($arg, '-')
                        ? 'Unknown option: ' . self::sanitize($arg)
                        : 'Unexpected argument: ' . self::sanitize($arg);
                    return $this->emitError($msg);
            }
        }

        if ($count < 1) {
            return $this->emitError('Count must be a positive integer');
        }
        if ($count > 10000) {
            return $this->emitError('Count must not exceed 10,000');
        }

        try {
            $gen = new HybridIdGenerator(profile: $profile, node: $node, requireExplicitNode: false, blind: $blind);
        } catch (\InvalidArgumentException $e) {
            return $this->emitError(self::sanitize($e->getMessage()));
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            try {
                $ids[] = $gen->generate($prefix);
            } catch (\Throwable $e) {
                return $this->emitError(self::sanitize($e->getMessage()));
            }
        }

        if ($this->json) {
            $this->output->writeln(self::encodeJson(['ids' => $ids]));
        } else {
            foreach ($ids as $id) {
                $this->output->writeln($id);
            }
        }

        return 0;
    }

    /**
     * @param list<string> $args
     */
    private function commandInspect(array $args): int
    {
        $id = $args[0] ?? null;

        if ($id === null || $id === '') {
            return $this->emitError('Usage: hybrid-id inspect <id>');
        }

        $profile = HybridIdGenerator::detectProfile($id);

        if ($profile === null) {
            return $this->emitError('Invalid HybridId: ' . self::sanitize($id));
        }

        $prefix = HybridIdGenerator::extractPrefix($id);
        $config = HybridIdGenerator::profileConfig($profile);
        $timestamp = HybridIdGenerator::extractTimestamp($id);
        $datetime = HybridIdGenerator::extractDateTime($id);
        $node = HybridIdGenerator::extractNode($id);
        $rawId = $prefix !== null ? substr($id, strlen($prefix) + 1) : $id;
        $randomOffset = 8 + $config['node'];
        $random = substr($rawId, $randomOffset);
        $entropy = HybridIdGenerator::entropy($profile);

        if ($this->json) {
            $this->output->writeln(self::encodeJson([
                'id' => $id,
                'prefix' => $prefix,
                'profile' => $profile,
                'length' => $config['length'],
                'timestamp' => $timestamp,
                'datetime' => $datetime->format('Y-m-d H:i:s.v'),
                'node' => $node,
                'random' => $random,
                'entropy_bits' => $entropy,
                'valid' => true,
            ]));
            return 0;
        }

        $this->output->writeln('');
        $this->output->writeln("  ID:         {$id}");
        if ($prefix !== null) {
            $this->output->writeln("  Prefix:     {$prefix}");
        }
        $this->output->writeln("  Profile:    {$profile} ({$config['length']} chars)");
        $this->output->writeln("  Timestamp:  {$timestamp}");
        $this->output->writeln("  DateTime:   {$datetime->format('Y-m-d H:i:s.v')}");
        if ($node !== null) {
            $this->output->writeln("  Node:       {$node}");
        }
        $this->output->writeln("  Random:     {$random}");
        $this->output->writeln("  Entropy:    {$entropy} bits");
        $this->output->writeln('  Valid:      yes');
        $this->output->writeln('');

        return 0;
    }

    private function commandProfiles(): int
    {
        $profiles = [];
        foreach (HybridIdGenerator::profiles() as $name) {
            $config = HybridIdGenerator::profileConfig($name);
            $entropy = HybridIdGenerator::entropy($name);
            $profiles[] = [
                'name' => $name,
                'length' => $config['length'],
                'ts' => $config['ts'],
                'node' => $config['node'],
                'random' => $config['random'],
                'entropy_bits' => $entropy,
            ];
        }

        if ($this->json) {
            $this->output->writeln(self::encodeJson(['profiles' => $profiles]));
            return 0;
        }

        $comparisons = [
            'compact'  => '< UUID v7',
            'standard' => '~ UUID v7',
            'extended' => '> UUID v7',
        ];

        $this->output->writeln('');
        $this->output->writeln('  Profile     Length   Structure              Random bits   vs UUID v7');
        $this->output->writeln('  -------     ------   ---------              -----------   ----------');

        foreach ($profiles as $p) {
            $structure = $p['node'] > 0
                ? "{$p['ts']}ts + {$p['node']}node + {$p['random']}rand"
                : "{$p['ts']}ts + {$p['random']}rand";
            $cmp = $comparisons[$p['name']] ?? 'custom';

            $this->output->writeln(sprintf(
                '  %-10s  %-7d  %-21s  %-12s  %s',
                $p['name'],
                $p['length'],
                $structure,
                "{$p['entropy_bits']} bits",
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
        $this->output->writeln('  --blind                Generate using blind mode (requires HYBRID_ID_BLIND_SECRET)');
        $this->output->writeln('');
        $this->output->writeln('Global options:');
        $this->output->writeln('  --json                 Output in JSON format (generate, inspect, profiles)');
        $this->output->writeln('');
        $this->output->writeln('Examples:');
        $this->output->writeln('  hybrid-id generate');
        $this->output->writeln('  hybrid-id generate -p compact -n 10');
        $this->output->writeln('  hybrid-id generate -p extended --node A1');
        $this->output->writeln('  hybrid-id generate --prefix usr');
        $this->output->writeln('  hybrid-id inspect usr_0A1b2C3dX9YyZzWwQq12');
        $this->output->writeln('  hybrid-id generate --json -n 3');
        $this->output->writeln('');

        return $unknown !== null ? 1 : 0;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private const int MAX_INPUT_LENGTH = 256;

    /**
     * Emit an error in the active output format (text or JSON) and return exit code 1.
     */
    private function emitError(string $message): int
    {
        if ($this->json) {
            $this->output->error(self::encodeJson(['error' => $message]));
        } else {
            $this->output->error($message);
        }
        return 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeJson(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private static function sanitize(string $input): string
    {
        $cleaned = (string) preg_replace('/[^\x20-\x7e]/', '', $input);

        if (strlen($cleaned) > self::MAX_INPUT_LENGTH) {
            return substr($cleaned, 0, self::MAX_INPUT_LENGTH);
        }

        return $cleaned;
    }
}
