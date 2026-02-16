<?php

declare(strict_types=1);

namespace HybridId;

final class HybridIdGenerator implements IdGenerator
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

    private const string PREFIX_SEPARATOR = '_';
    private const int PREFIX_MAX_LENGTH = 8;

    /** @var array<string, array{length: int, ts: int, node: int, random: int}> */
    private static array $customProfiles = [];

    /** @var array<int, string> */
    private static array $customLengthToProfile = [];

    private readonly string $profile;
    private readonly string $node;
    private readonly ?int $maxIdLength;
    private int $lastTimestamp = 0;

    public function __construct(
        string $profile = 'standard',
        ?string $node = null,
        ?int $maxIdLength = null,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('HybridId requires 64-bit PHP');
        }

        if (self::resolveProfile($profile) === null) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid profile "%s". Valid profiles: %s',
                    $profile,
                    implode(', ', self::profiles()),
                ),
            );
        }
        $this->profile = $profile;

        if ($node !== null) {
            if (strlen($node) !== 2 || !self::isBase62String($node)) {
                throw new \InvalidArgumentException('Node must be exactly 2 base62 characters (0-9, A-Z, a-z)');
            }
            $this->node = $node;
        } else {
            $this->node = self::autoDetectNode();
        }

        if ($maxIdLength !== null) {
            $bodyLength = self::resolveProfile($this->profile)['length'];
            if ($maxIdLength < $bodyLength) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'maxIdLength (%d) must be >= body length (%d) for profile "%s"',
                        $maxIdLength,
                        $bodyLength,
                        $this->profile,
                    ),
                );
            }
        }
        $this->maxIdLength = $maxIdLength;
    }

    /**
     * Create an instance configured from environment variables.
     *
     * Reads HYBRID_ID_PROFILE and HYBRID_ID_NODE from the environment.
     * Pairs well with vlucas/phpdotenv for .env file support.
     */
    public static function fromEnv(): self
    {
        $profile = getenv('HYBRID_ID_PROFILE', true) ?: getenv('HYBRID_ID_PROFILE');
        $node = getenv('HYBRID_ID_NODE', true) ?: getenv('HYBRID_ID_NODE');

        return new self(
            profile: ($profile !== false && $profile !== '') ? $profile : 'standard',
            node: ($node !== false && $node !== '') ? $node : null,
        );
    }

    // -------------------------------------------------------------------------
    // Generation (instance methods)
    // -------------------------------------------------------------------------

    /**
     * Generate an ID using this instance's configured profile.
     */
    public function generate(?string $prefix = null): string
    {
        return $this->generateWithProfile($this->profile, $prefix);
    }

    /**
     * Generate a compact ID (16 chars: 8ts + 2node + 6random, ~35.7 bits entropy).
     *
     * @note For multi-node deployments with more than a few hundred IDs/second,
     *       prefer standard() or extended() and set explicit node IDs.
     */
    public function compact(?string $prefix = null): string
    {
        return $this->generateWithProfile('compact', $prefix);
    }

    /**
     * Generate a standard ID (20 chars: 8ts + 2node + 10random, ~59.5 bits entropy).
     */
    public function standard(?string $prefix = null): string
    {
        return $this->generateWithProfile('standard', $prefix);
    }

    /**
     * Generate an extended ID (24 chars: 8ts + 2node + 14random, ~83.4 bits entropy).
     */
    public function extended(?string $prefix = null): string
    {
        return $this->generateWithProfile('extended', $prefix);
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    /**
     * Get the body length (without prefix) for this instance's profile.
     */
    public function bodyLength(): int
    {
        return self::resolveProfile($this->profile)['length'];
    }

    public function getMaxIdLength(): ?int
    {
        return $this->maxIdLength;
    }

    // -------------------------------------------------------------------------
    // Instance validation
    // -------------------------------------------------------------------------

    /**
     * Validate that an ID matches this instance's profile and optionally a specific prefix.
     *
     * Unlike the static isValid(), this checks against the configured profile specifically.
     * This is a format check, not an authorization mechanism.
     *
     * @param string $id The ID to validate
     * @param string|null $expectedPrefix When provided, the ID's prefix must match exactly
     */
    public function validate(string $id, ?string $expectedPrefix = null): bool
    {
        // Early guards
        if ($id === '' || strlen($id) > 147) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
            return false;
        }

        // Strip prefix and check body length against THIS profile
        $body = self::stripPrefix($id);
        $config = self::resolveProfile($this->profile);

        if (strlen($body) !== $config['length']) {
            return false;
        }

        if (!self::isBase62String($body)) {
            return false;
        }

        // Prefix validation
        $prefix = self::extractPrefix($id);

        if ($expectedPrefix !== null) {
            return $prefix === $expectedPrefix;
        }

        // If has prefix, validate its format
        if ($prefix !== null) {
            if (strlen($prefix) > self::PREFIX_MAX_LENGTH
                || !preg_match('/^[a-z][a-z0-9]*$/', $prefix)) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Static utilities (no instance needed)
    // -------------------------------------------------------------------------

    /**
     * Validate that a string is a well-formed HybridId of any known profile length.
     * Handles both prefixed and unprefixed IDs.
     */
    public static function isValid(string $id): bool
    {
        return self::detectProfile($id) !== null;
    }

    /**
     * Calculate the recommended database column size for a profile.
     *
     * @param int $maxPrefixLength Maximum prefix length to accommodate (0 = no prefix)
     * @return int Column size in characters
     */
    public static function recommendedColumnSize(string $profile, int $maxPrefixLength = 0): int
    {
        if ($maxPrefixLength < 0 || $maxPrefixLength > self::PREFIX_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('maxPrefixLength must be between 0 and %d', self::PREFIX_MAX_LENGTH),
            );
        }

        $bodyLength = self::profileConfig($profile)['length'];

        if ($maxPrefixLength === 0) {
            return $bodyLength;
        }

        return $maxPrefixLength + 1 + $bodyLength; // prefix + underscore + body
    }

    /**
     * Parse a HybridId into all its components in a single pass.
     *
     * Returns an array with 'valid' => true and all components for valid IDs,
     * or 'valid' => false with partial data for invalid IDs.
     *
     * @return array{valid: bool, prefix: ?string, profile?: string, body?: string, timestamp?: int, datetime?: \DateTimeImmutable, node?: string, random?: string}
     */
    public static function parse(string $id): array
    {
        if ($id === '' || strlen($id) > 147 || !preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
            return ['valid' => false, 'prefix' => null, 'body' => null];
        }

        $prefix = self::extractPrefix($id);
        $body = self::stripPrefix($id);
        $profile = self::detectProfile($id);

        if ($profile === null) {
            return [
                'valid' => false,
                'prefix' => $prefix,
                'body' => $body,
            ];
        }

        $timestamp = self::decodeBase62(substr($body, 0, 8));
        $seconds = intdiv($timestamp, 1000);
        $microseconds = ($timestamp % 1000) * 1000;

        $datetime = \DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $microseconds),
        );

        return [
            'valid' => true,
            'prefix' => $prefix,
            'profile' => $profile,
            'body' => $body,
            'timestamp' => $timestamp,
            'datetime' => $datetime !== false ? $datetime : null,
            'node' => substr($body, 8, 2),
            'random' => substr($body, 10),
        ];
    }

    /**
     * Extract the millisecond timestamp from a HybridId (with or without prefix).
     */
    public static function extractTimestamp(string $id): int
    {
        self::assertValid($id);

        $raw = self::stripPrefix($id);

        return self::decodeBase62(substr($raw, 0, 8));
    }

    /**
     * Extract a DateTimeImmutable from a HybridId (with or without prefix).
     *
     * @note Under high throughput the monotonic guard increments timestamps
     *       artificially, so the returned time may be slightly ahead of the
     *       actual wall-clock time at which the ID was created.
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
     * Extract the 2-character node identifier from a HybridId (with or without prefix).
     */
    public static function extractNode(string $id): string
    {
        self::assertValid($id);

        $raw = self::stripPrefix($id);

        return substr($raw, 8, 2);
    }

    /**
     * Extract the prefix from a HybridId, or null if unprefixed.
     */
    public static function extractPrefix(string $id): ?string
    {
        $pos = strpos($id, self::PREFIX_SEPARATOR);

        if ($pos === false || $pos === 0) {
            return null;
        }

        return substr($id, 0, $pos);
    }

    /**
     * Detect which profile a HybridId belongs to, or null if invalid.
     * Handles both prefixed and unprefixed IDs.
     */
    public static function detectProfile(string $id): ?string
    {
        $raw = self::stripPrefix($id);

        // Validate prefix format if present
        $prefix = self::extractPrefix($id);
        if ($raw !== $id && $prefix === null) {
            return null;
        }
        if ($prefix !== null) {
            if (strlen($prefix) > self::PREFIX_MAX_LENGTH
                || !preg_match('/^[a-z][a-z0-9]*$/', $prefix)) {
                return null;
            }
        }

        $profile = self::resolveProfileByLength(strlen($raw));

        if ($profile === null || !self::isBase62String($raw)) {
            return null;
        }

        return $profile;
    }

    /**
     * Get the random entropy bits for a given profile.
     */
    public static function entropy(string $profile): float
    {
        $config = self::resolveProfile($profile);

        if ($config === null) {
            throw new \InvalidArgumentException(
                sprintf('Invalid profile "%s". Valid profiles: %s', $profile, implode(', ', self::profiles())),
            );
        }

        return round($config['random'] * log(62, 2), 1);
    }

    /**
     * Get profile configuration details.
     *
     * @return array{length: int, ts: int, node: int, random: int}
     */
    public static function profileConfig(string $profile): array
    {
        $config = self::resolveProfile($profile);

        if ($config === null) {
            throw new \InvalidArgumentException(
                sprintf('Invalid profile "%s". Valid profiles: %s', $profile, implode(', ', self::profiles())),
            );
        }

        return $config;
    }

    /**
     * Get all available profile names (built-in + custom).
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        return [...array_keys(self::PROFILES), ...array_keys(self::$customProfiles)];
    }

    /**
     * Register a custom profile with a given random length.
     *
     * Timestamp (8) and node (2) are fixed — only random is configurable.
     * Total length = 10 + random.
     */
    public static function registerProfile(string $name, int $random): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            throw new \InvalidArgumentException('Profile name must be lowercase alphanumeric, starting with a letter');
        }

        if (self::resolveProfile($name) !== null) {
            throw new \InvalidArgumentException(
                sprintf('Profile "%s" already exists', $name),
            );
        }

        if ($random < 6 || $random > 128) {
            throw new \InvalidArgumentException('Random length must be between 6 and 128');
        }

        $length = 8 + 2 + $random;

        $existing = self::resolveProfileByLength($length);
        if ($existing !== null) {
            throw new \InvalidArgumentException(
                sprintf('Length %d conflicts with existing profile "%s"', $length, $existing),
            );
        }

        self::$customProfiles[$name] = [
            'length' => $length,
            'ts' => 8,
            'node' => 2,
            'random' => $random,
        ];
        self::$customLengthToProfile[$length] = $name;
    }

    /**
     * Remove all custom profiles.
     *
     * @internal Intended for testing only.
     */
    public static function resetProfiles(): void
    {
        self::$customProfiles = [];
        self::$customLengthToProfile = [];
    }

    /**
     * Compare two HybridIds chronologically.
     *
     * Returns -1, 0, or 1 — compatible with usort() and spaceship operator convention.
     * Handles prefixed IDs by stripping prefixes before comparison.
     */
    public static function compare(string $a, string $b): int
    {
        return self::extractTimestamp($a) <=> self::extractTimestamp($b);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @return array{length: int, ts: int, node: int, random: int}|null
     */
    private static function resolveProfile(string $name): ?array
    {
        return self::PROFILES[$name] ?? self::$customProfiles[$name] ?? null;
    }

    private static function resolveProfileByLength(int $length): ?string
    {
        return self::LENGTH_TO_PROFILE[$length] ?? self::$customLengthToProfile[$length] ?? null;
    }

    private function generateWithProfile(string $profile, ?string $prefix): string
    {
        $config = self::resolveProfile($profile);

        $now = (int) (microtime(true) * 1000);

        // Monotonic guard: if clock drifts backward or same ms, increment to guarantee
        // strict ordering and eliminate intra-millisecond collision on the timestamp portion.
        if ($now <= $this->lastTimestamp) {
            $now = $this->lastTimestamp + 1;
        }

        $timestamp = self::encodeBase62($now, $config['ts']);
        $random = self::randomBase62($config['random']);

        // Only update after successful generation to prevent counter desync on failure.
        $this->lastTimestamp = $now;

        $id = $timestamp . $this->node . $random;
        $fullId = self::applyPrefix($id, $prefix);

        if ($this->maxIdLength !== null && strlen($fullId) > $this->maxIdLength) {
            throw new \OverflowException(
                sprintf(
                    'Generated ID length %d exceeds maxIdLength %d. Use a shorter prefix or increase maxIdLength',
                    strlen($fullId),
                    $this->maxIdLength,
                ),
            );
        }

        return $fullId;
    }

    /**
     * Derive a deterministic 2-char node from hostname + PID.
     *
     * Produces 3,844 possible values (62^2). By the birthday paradox,
     * 50% collision probability is reached at ~74 distinct host:pid pairs.
     * For deployments with more than a handful of nodes, set the node explicitly.
     */
    private static function autoDetectNode(): string
    {
        $raw = (gethostname() ?: 'unknown') . ':' . getmypid();
        $hash = crc32($raw) & 0x7FFFFFFF;
        // 62^2 = 3844 possible values, fitting exactly 2 base62 chars.
        // Modulo bias: 2^31 % 3844 = 3148 non-zero, so values [0, 695] have a
        // 1-in-558932 (~0.00018%) higher probability. Negligible for node hashing.
        $nodeNum = $hash % 3844;

        return self::encodeBase62($nodeNum, 2);
    }

    private static function encodeBase62(int $num, int $length): string
    {
        if ($num === 0) {
            return str_repeat('0', $length);
        }

        $encoded = '';
        while ($num > 0) {
            $encoded = self::BASE62[$num % 62] . $encoded;
            $num = intdiv($num, 62);
        }

        $encoded = str_pad($encoded, $length, '0', STR_PAD_LEFT);

        if (strlen($encoded) > $length) {
            throw new \OverflowException(
                sprintf('Value exceeds maximum for %d base62 characters', $length),
            );
        }

        return $encoded;
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
        $limit = 248; // largest multiple of 62 ≤ 255 (4 × 62), eliminates modulo bias
        $chars = [];
        $buffer = random_bytes((int) ceil($length * 1.25));
        $pos = 0;
        $bufLen = strlen($buffer);

        while (count($chars) < $length) {
            if ($pos >= $bufLen) {
                $buffer = random_bytes((int) ceil(($length - count($chars)) * 1.25));
                $pos = 0;
                $bufLen = strlen($buffer);
            }

            $byte = ord($buffer[$pos++]);

            if ($byte < $limit) {
                $chars[] = self::BASE62[$byte % 62];
            }
        }

        return implode('', $chars);
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

    private static function applyPrefix(string $id, ?string $prefix): string
    {
        if ($prefix === null) {
            return $id;
        }

        self::validatePrefix($prefix);

        return $prefix . self::PREFIX_SEPARATOR . $id;
    }

    private static function validatePrefix(string $prefix): void
    {
        if ($prefix === '' || strlen($prefix) > self::PREFIX_MAX_LENGTH
            || !preg_match('/^[a-z][a-z0-9]*$/', $prefix)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Prefix must be 1-%d lowercase alphanumeric characters, starting with a letter',
                    self::PREFIX_MAX_LENGTH,
                ),
            );
        }
    }

    private static function stripPrefix(string $id): string
    {
        $pos = strpos($id, self::PREFIX_SEPARATOR);

        if ($pos === false) {
            return $id;
        }

        return substr($id, $pos + 1);
    }
}
