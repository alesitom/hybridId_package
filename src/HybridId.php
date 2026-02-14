<?php

declare(strict_types=1);

namespace HybridId;

final class HybridId
{
    private const string BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** @var array<string, int> Reverse lookup: character => position for O(1) decoding */
    private const array BASE62_MAP = [
        '0' => 0,  '1' => 1,  '2' => 2,  '3' => 3,  '4' => 4,  '5' => 5,  '6' => 6,  '7' => 7,
        '8' => 8,  '9' => 9,  'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 'F' => 15,
        'G' => 16, 'H' => 17, 'I' => 18, 'J' => 19, 'K' => 20, 'L' => 21, 'M' => 22, 'N' => 23,
        'O' => 24, 'P' => 25, 'Q' => 26, 'R' => 27, 'S' => 28, 'T' => 29, 'U' => 30, 'V' => 31,
        'W' => 32, 'X' => 33, 'Y' => 34, 'Z' => 35, 'a' => 36, 'b' => 37, 'c' => 38, 'd' => 39,
        'e' => 40, 'f' => 41, 'g' => 42, 'h' => 43, 'i' => 44, 'j' => 45, 'k' => 46, 'l' => 47,
        'm' => 48, 'n' => 49, 'o' => 50, 'p' => 51, 'q' => 52, 'r' => 53, 's' => 54, 't' => 55,
        'u' => 56, 'v' => 57, 'w' => 58, 'x' => 59, 'y' => 60, 'z' => 61,
    ];

    /** @var array<string, array{length: int, ts: int, node: int, random: int}> */
    private const array PROFILES = [
        'compact'  => ['length' => 16, 'ts' => 8, 'node' => 2, 'random' => 6],
        'standard' => ['length' => 20, 'ts' => 8, 'node' => 2, 'random' => 10],
        'extended' => ['length' => 24, 'ts' => 8, 'node' => 2, 'random' => 14],
    ];

    /** @var array<int, string> Reverse lookup: length => profile name for O(1) detection */
    private const array LENGTH_TO_PROFILE = [
        16 => 'compact',
        20 => 'standard',
        24 => 'extended',
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
     *
     * @note For multi-node deployments with more than a few hundred IDs/second,
     *       prefer standard() or extended() and set explicit node IDs via configure().
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
            throw new \RuntimeException(
                sprintf('Failed to create DateTime from HybridId (timestamp: %d ms)', $timestampMs),
            );
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
        $profile = self::LENGTH_TO_PROFILE[strlen($id)] ?? null;

        if ($profile === null || !self::isBase62String($id)) {
            return null;
        }

        return $profile;
    }

    /**
     * Get the random entropy bits for a given profile (or current default).
     */
    public static function entropy(?string $profile = null): float
    {
        $profile ??= self::$profile;

        if (!isset(self::PROFILES[$profile])) {
            throw new \InvalidArgumentException(
                sprintf('Invalid profile "%s". Valid profiles: %s', $profile, implode(', ', array_keys(self::PROFILES))),
            );
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
            throw new \InvalidArgumentException(
                sprintf('Invalid profile "%s". Valid profiles: %s', $profile, implode(', ', array_keys(self::PROFILES))),
            );
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
        return ['compact', 'standard', 'extended'];
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

        $profile = getenv('HYBRID_ID_PROFILE', true) ?: getenv('HYBRID_ID_PROFILE');
        if ($profile !== false && $profile !== '') {
            $options['profile'] = $profile;
        }

        $node = getenv('HYBRID_ID_NODE', true) ?: getenv('HYBRID_ID_NODE');
        if ($node !== false && $node !== '') {
            $options['node'] = $node;
        }

        if ($options !== []) {
            self::configure($options);
        }
    }

    /**
     * Reset all configuration to defaults.
     *
     * @internal Intended for testing only. Do not call in production — breaks monotonic guarantee.
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
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('HybridId requires 64-bit PHP');
        }

        $config = self::PROFILES[$profile];

        $now = (int) (microtime(true) * 1000);

        // Monotonic guard: if clock drifts backward or same ms, increment to guarantee
        // strict ordering and eliminate intra-millisecond collision on the timestamp portion.
        if ($now <= self::$lastTimestamp) {
            $now = self::$lastTimestamp + 1;
        }

        $timestamp = self::encodeBase62($now, $config['ts']);
        $node = self::resolveNode();
        $random = self::randomBase62($config['random']);

        // Only update after successful generation to prevent counter desync on failure.
        self::$lastTimestamp = $now;

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
        if ($num === 0) {
            return str_repeat('0', $length);
        }

        $chars = [];
        while ($num > 0) {
            $chars[] = self::BASE62[$num % 62];
            $num = intdiv($num, 62);
        }

        return str_pad(implode('', array_reverse($chars)), $length, '0', STR_PAD_LEFT);
    }

    private static function decodeBase62(string $str): int
    {
        $result = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $pos = self::BASE62_MAP[$str[$i]] ?? null;

            if ($pos === null) {
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

        return strspn($str, self::BASE62) === strlen($str);
    }

    private static function assertValid(string $id): void
    {
        if (!self::isValid($id)) {
            throw new \InvalidArgumentException('Invalid HybridId format');
        }
    }
}
