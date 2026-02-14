<?php

declare(strict_types=1);

namespace HybridId;

final class HybridId
{
    private const string BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** @var array<string, array{length: int, ts: int, node: int, random: int}> */
    private const array PROFILES = [
        'compact'  => ['length' => 16, 'ts' => 8, 'node' => 2, 'random' => 6],
        'standard' => ['length' => 20, 'ts' => 8, 'node' => 2, 'random' => 10],
        'extended' => ['length' => 24, 'ts' => 8, 'node' => 2, 'random' => 14],
    ];

    private static string $profile = 'standard';
    private static string $node = '';
    private static int $lastTimestamp = 0;

    private function __construct() {}

    /**
     * Configure global defaults.
     *
     * @param array{profile?: string, node?: string} $options
     */
    public static function configure(array $options): void
    {
        if (isset($options['profile'])) {
            if (!isset(self::PROFILES[$options['profile']])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid profile "%s". Valid profiles: %s',
                        $options['profile'],
                        implode(', ', array_keys(self::PROFILES)),
                    ),
                );
            }
            self::$profile = $options['profile'];
        }

        if (isset($options['node'])) {
            $node = $options['node'];
            if (strlen($node) !== 2 || !self::isBase62String($node)) {
                throw new \InvalidArgumentException('Node must be exactly 2 base62 characters (0-9, A-Z, a-z)');
            }
            self::$node = $node;
        }
    }

    /**
     * Generate an ID using the configured default profile.
     */
    public static function generate(): string
    {
        return self::generateWithProfile(self::$profile);
    }

    /**
     * Generate a compact ID (16 chars: 8ts + 2node + 6random, ~35.7 bits entropy).
     */
    public static function compact(): string
    {
        return self::generateWithProfile('compact');
    }

    /**
     * Generate a standard ID (20 chars: 8ts + 2node + 10random, ~59.5 bits entropy).
     */
    public static function standard(): string
    {
        return self::generateWithProfile('standard');
    }

    /**
     * Generate an extended ID (24 chars: 8ts + 2node + 14random, ~83.4 bits entropy).
     */
    public static function extended(): string
    {
        return self::generateWithProfile('extended');
    }

    /**
     * Validate that a string is a well-formed HybridId of any known profile length.
     */
    public static function isValid(string $id): bool
    {
        return self::detectProfile($id) !== null;
    }

    /**
     * Extract the millisecond timestamp from a HybridId.
     */
    public static function extractTimestamp(string $id): int
    {
        self::assertValid($id);

        return self::decodeBase62(substr($id, 0, 8));
    }

    /**
     * Extract a DateTimeImmutable from a HybridId.
     */
    public static function extractDateTime(string $id): \DateTimeImmutable
    {
        $timestampMs = self::extractTimestamp($id);
        $seconds = intdiv($timestampMs, 1000);
        $microseconds = ($timestampMs % 1000) * 1000;

        $dt = \DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        );

        if ($dt === false) {
            throw new \RuntimeException('Failed to create DateTime from HybridId');
        }

        return $dt;
    }

    /**
     * Extract the 2-character node identifier from a HybridId.
     */
    public static function extractNode(string $id): string
    {
        self::assertValid($id);

        return substr($id, 8, 2);
    }

    /**
     * Detect which profile a HybridId belongs to, or null if invalid.
     */
    public static function detectProfile(string $id): ?string
    {
        $length = strlen($id);

        if (!self::isBase62String($id)) {
            return null;
        }

        foreach (self::PROFILES as $name => $config) {
            if ($length === $config['length']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Get the random entropy bits for a given profile (or current default).
     */
    public static function entropy(?string $profile = null): float
    {
        $profile ??= self::$profile;

        if (!isset(self::PROFILES[$profile])) {
            throw new \InvalidArgumentException("Invalid profile: {$profile}");
        }

        return round(self::PROFILES[$profile]['random'] * log(62, 2), 1);
    }

    /**
     * Get profile configuration details.
     *
     * @return array{length: int, ts: int, node: int, random: int}
     */
    public static function profileConfig(?string $profile = null): array
    {
        $profile ??= self::$profile;

        if (!isset(self::PROFILES[$profile])) {
            throw new \InvalidArgumentException("Invalid profile: {$profile}");
        }

        return self::PROFILES[$profile];
    }

    /**
     * Get all available profile names.
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * Configure from environment variables.
     *
     * Reads HYBRID_ID_PROFILE and HYBRID_ID_NODE from the environment.
     * Pairs well with vlucas/phpdotenv for .env file support.
     *
     * .env example:
     *   HYBRID_ID_PROFILE=standard
     *   HYBRID_ID_NODE=A1
     */
    public static function configureFromEnv(): void
    {
        $options = [];

        $profile = getenv('HYBRID_ID_PROFILE');
        if ($profile !== false && $profile !== '') {
            $options['profile'] = $profile;
        }

        $node = getenv('HYBRID_ID_NODE');
        if ($node !== false && $node !== '') {
            $options['node'] = $node;
        }

        if ($options !== []) {
            self::configure($options);
        }
    }

    /**
     * Reset all configuration to defaults. Useful for testing.
     */
    public static function reset(): void
    {
        self::$profile = 'standard';
        self::$node = '';
        self::$lastTimestamp = 0;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function generateWithProfile(string $profile): string
    {
        $config = self::PROFILES[$profile];

        $now = (int) (microtime(true) * 1000);

        // Monotonic guard: prevent timestamp from going backward (clock drift / NTP)
        $now = max($now, self::$lastTimestamp);
        self::$lastTimestamp = $now;

        $timestamp = self::encodeBase62($now, $config['ts']);
        $node = self::resolveNode();
        $random = self::randomBase62($config['random']);

        return $timestamp . $node . $random;
    }

    private static function resolveNode(): string
    {
        if (self::$node !== '') {
            return self::$node;
        }

        $raw = (gethostname() ?: 'unknown') . ':' . getmypid();
        $hash = crc32($raw) & 0x7FFFFFFF;
        $nodeNum = $hash % 3844; // 62^2 = 3844, fits in exactly 2 base62 chars

        return self::encodeBase62($nodeNum, 2);
    }

    private static function encodeBase62(int $num, int $length): string
    {
        $result = '';

        while ($num > 0) {
            $result = self::BASE62[$num % 62] . $result;
            $num = intdiv($num, 62);
        }

        return str_pad($result, $length, '0', STR_PAD_LEFT);
    }

    private static function decodeBase62(string $str): int
    {
        $result = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::BASE62, $str[$i]);

            if ($pos === false) {
                throw new \InvalidArgumentException("Invalid base62 character: {$str[$i]}");
            }

            $result = $result * 62 + $pos;
        }

        return $result;
    }

    private static function randomBase62(int $length): string
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= self::BASE62[random_int(0, 61)];
        }

        return $result;
    }

    private static function isBase62String(string $str): bool
    {
        if ($str === '') {
            return false;
        }

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            if (!str_contains(self::BASE62, $str[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function assertValid(string $id): void
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('Invalid HybridId format');
        }
    }
}
