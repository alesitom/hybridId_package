<?php

declare(strict_types=1);

namespace HybridId;

use HybridId\Exception\IdOverflowException;
use HybridId\Exception\InvalidIdException;
use HybridId\Exception\InvalidPrefixException;
use HybridId\Exception\InvalidProfileException;
use HybridId\Exception\NodeRequiredException;

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

    private const string PREFIX_SEPARATOR = '_';
    private const int PREFIX_MAX_LENGTH = 8;

    /**
     * Maximum possible ID length: PREFIX_MAX_LENGTH (8) + separator (1) + max body (138).
     * Max body = 8 ts + 2 node + 128 random (registerProfile limit).
     */
    private const int MAX_ID_LENGTH = 147;

    private static ?ProfileRegistry $defaultRegistryInstance = null;

    private readonly ProfileRegistryInterface $registry;
    private readonly string $profile;
    /** @var array{length: int, ts: int, node: int, random: int} */
    private readonly array $profileConfig;
    private readonly string $node;
    private readonly ?int $maxIdLength;
    private readonly bool $blind;
    private readonly ?string $blindSecret;
    private int $lastTimestamp = 0;

    public function __construct(
        Profile|string $profile = Profile::Standard,
        ?string $node = null,
        ?int $maxIdLength = null,
        bool $requireExplicitNode = true,
        ?ProfileRegistryInterface $registry = null,
        bool $blind = false,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('HybridId requires 64-bit PHP');
        }

        $this->registry = $registry ?? self::defaultRegistry();

        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $config = $this->registry->get($profileName);
        if ($config === null) {
            throw new InvalidProfileException(
                'Invalid profile. Valid profiles: ' . implode(', ', $this->registry->all()),
            );
        }
        $this->profile = $profileName;
        $this->profileConfig = $config;
        $this->blind = $blind;
        $this->blindSecret = $blind ? random_bytes(32) : null;

        if ($node !== null) {
            if (strlen($node) !== 2 || !self::isBase62String($node)) {
                throw new \InvalidArgumentException('Node must be exactly 2 base62 characters (0-9, A-Z, a-z)');
            }
            $this->node = $node;
        } elseif ($blind) {
            // Blind mode: node is only used as HMAC input, secret differentiates instances
            $this->node = self::autoDetectNode();
        } elseif ($requireExplicitNode && $this->profileConfig['node'] > 0) {
            throw new NodeRequiredException(
                'Explicit node is required (requireExplicitNode is enabled). '
                . 'Provide a 2-character base62 node identifier via the node parameter or HYBRID_ID_NODE env var.',
            );
        } else {
            $this->node = self::autoDetectNode();
        }

        if ($maxIdLength !== null) {
            if ($maxIdLength < $this->profileConfig['length']) {
                throw new IdOverflowException(
                    sprintf(
                        'maxIdLength (%d) must be >= body length (%d) for profile "%s"',
                        $maxIdLength,
                        $this->profileConfig['length'],
                        $profileName,
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
    public static function fromEnv(?ProfileRegistryInterface $registry = null): self
    {
        $profileValue = getenv('HYBRID_ID_PROFILE', true) ?: getenv('HYBRID_ID_PROFILE');
        $profileValue = ($profileValue !== false && $profileValue !== '') ? $profileValue : 'standard';
        $profile = Profile::tryFrom($profileValue) ?? $profileValue;

        $node = getenv('HYBRID_ID_NODE', true) ?: getenv('HYBRID_ID_NODE');
        $requireNode = getenv('HYBRID_ID_REQUIRE_NODE', true);
        if ($requireNode === false) {
            $requireNode = getenv('HYBRID_ID_REQUIRE_NODE');
        }

        // When HYBRID_ID_REQUIRE_NODE is not set, use the constructor default (true).
        // Set HYBRID_ID_REQUIRE_NODE=0 to explicitly disable the guard.
        $requireExplicit = ($requireNode === false || $requireNode === '')
            ? true
            : $requireNode !== '0';

        $blindEnv = getenv('HYBRID_ID_BLIND', true);
        if ($blindEnv === false) {
            $blindEnv = getenv('HYBRID_ID_BLIND');
        }
        $blind = ($blindEnv !== false && $blindEnv !== '' && $blindEnv !== '0');

        return new self(
            profile: $profile,
            node: ($node !== false && $node !== '') ? $node : null,
            requireExplicitNode: $requireExplicit,
            registry: $registry,
            blind: $blind,
        );
    }

    private static function defaultRegistry(): ProfileRegistry
    {
        return self::$defaultRegistryInstance ??= ProfileRegistry::withDefaults();
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

    public function generateBatch(int $count, ?string $prefix = null): array
    {
        if ($count < 1 || $count > 100_000) {
            throw new \InvalidArgumentException(
                sprintf('Batch count must be between 1 and 100,000, got %d', $count),
            );
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate($prefix);
        }

        return $ids;
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
        return $this->profileConfig['length'];
    }

    public function getMaxIdLength(): ?int
    {
        return $this->maxIdLength;
    }

    public function isBlind(): bool
    {
        return $this->blind;
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
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
            return false;
        }

        // Strip prefix and check body length against THIS profile
        $body = self::stripPrefix($id);

        if (strlen($body) !== $this->profileConfig['length']) {
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

        if ($prefix !== null && !self::isValidPrefix($prefix)) {
            return false;
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
    public static function recommendedColumnSize(Profile|string $profile, int $maxPrefixLength = 0): int
    {
        if ($maxPrefixLength < 0 || $maxPrefixLength > self::PREFIX_MAX_LENGTH) {
            throw new InvalidPrefixException(
                sprintf('maxPrefixLength must be between 0 and %d', self::PREFIX_MAX_LENGTH),
            );
        }

        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $bodyLength = self::profileConfig($profileName)['length'];

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
     * @return array{valid: bool, prefix: ?string, body: ?string, profile?: string, timestamp?: int, datetime?: ?\DateTimeImmutable, node?: ?string, random?: string}
     */
    public static function parse(string $id): array
    {
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH || !preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
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

        $config = self::profileConfig($profile);
        $nodeLen = $config['node'];

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
            'node' => $nodeLen > 0 ? substr($body, 8, $nodeLen) : null,
            'random' => substr($body, 8 + $nodeLen),
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
     * Extract the node identifier from a HybridId, or null for node-less profiles (compact).
     */
    public static function extractNode(string $id): ?string
    {
        self::assertValid($id);

        $profile = self::detectProfile($id);
        $config = self::profileConfig($profile);

        if ($config['node'] === 0) {
            return null;
        }

        $raw = self::stripPrefix($id);

        return substr($raw, 8, $config['node']);
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
        if ($prefix !== null && !self::isValidPrefix($prefix)) {
            return null;
        }

        $profile = self::defaultRegistry()->getByLength(strlen($raw));

        if ($profile === null || !self::isBase62String($raw)) {
            return null;
        }

        return $profile;
    }

    /**
     * Get the random entropy bits for a given profile.
     */
    public static function entropy(Profile|string $profile): float
    {
        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $config = self::defaultRegistry()->get($profileName);

        if ($config === null) {
            throw new InvalidProfileException(
                'Invalid profile. Valid profiles: ' . implode(', ', self::profiles()),
            );
        }

        return round($config['random'] * log(62, 2), 1);
    }

    /**
     * Get profile configuration details.
     *
     * @return array{length: int, ts: int, node: int, random: int}
     */
    public static function profileConfig(Profile|string $profile): array
    {
        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $config = self::defaultRegistry()->get($profileName);

        if ($config === null) {
            throw new InvalidProfileException(
                'Invalid profile. Valid profiles: ' . implode(', ', self::profiles()),
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
        return self::defaultRegistry()->all();
    }

    /**
     * Register a custom profile with a given random length.
     *
     * Timestamp (8) and node (2) are fixed — only random is configurable.
     * Total length = 10 + random.
     *
     * @note Call during application bootstrap only, before creating any
     *       generator instances. The profile registry is global (static) and
     *       mutations after generators are constructed will not affect
     *       existing instances' cached configuration.
     *
     * @deprecated 4.0.0 Use ProfileRegistry::register() via constructor injection instead.
     */
    public static function registerProfile(string $name, int $random): void
    {
        @trigger_error(
            'HybridIdGenerator::registerProfile() is deprecated since 4.0.0. Use ProfileRegistry injection instead.',
            E_USER_DEPRECATED,
        );
        self::defaultRegistry()->register($name, $random);
    }

    /**
     * Remove all custom profiles.
     *
     * @internal Intended for testing only.
     * @deprecated 4.0.0 Use ProfileRegistry::reset() via constructor injection instead.
     */
    public static function resetProfiles(): void
    {
        @trigger_error(
            'HybridIdGenerator::resetProfiles() is deprecated since 4.0.0. Use ProfileRegistry injection instead.',
            E_USER_DEPRECATED,
        );
        self::defaultRegistry()->reset();
    }

    /**
     * Compare two HybridIds with total ordering.
     *
     * Primary sort: timestamp (chronological). Tiebreaker: lexicographic on body.
     * Returns -1, 0, or 1 — compatible with usort() and spaceship operator convention.
     * Returns 0 only when both IDs are byte-identical (after prefix stripping).
     * Handles prefixed IDs by stripping prefixes before comparison.
     */
    public static function compare(string $a, string $b): int
    {
        $cmp = self::extractTimestamp($a) <=> self::extractTimestamp($b);

        return $cmp !== 0 ? $cmp : strcmp(self::stripPrefix($a), self::stripPrefix($b));
    }

    // -------------------------------------------------------------------------
    // Range helpers (for DB queries)
    // -------------------------------------------------------------------------

    /**
     * Return the lowest possible ID for a given timestamp and profile.
     *
     * Useful for constructing inclusive lower bounds in DB range queries:
     *   WHERE id >= minForTimestamp($startMs)
     */
    public static function minForTimestamp(int $timestampMs, Profile|string $profile = Profile::Standard): string
    {
        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $config = self::profileConfig($profileName);
        $ts = self::encodeBase62($timestampMs, $config['ts']);

        return $ts . str_repeat('0', $config['node'] + $config['random']);
    }

    /**
     * Return the highest possible ID for a given timestamp and profile.
     *
     * Useful for constructing inclusive upper bounds in DB range queries:
     *   WHERE id <= maxForTimestamp($endMs)
     */
    public static function maxForTimestamp(int $timestampMs, Profile|string $profile = Profile::Standard): string
    {
        $profileName = $profile instanceof Profile ? $profile->value : $profile;
        $config = self::profileConfig($profileName);
        $ts = self::encodeBase62($timestampMs, $config['ts']);

        return $ts . str_repeat('z', $config['node'] + $config['random']);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function generateWithProfile(string $profile, ?string $prefix): string
    {
        $config = $profile === $this->profile ? $this->profileConfig : $this->registry->get($profile);

        $now = (int) (microtime(true) * 1000);

        // Monotonic guard: if clock drifts backward or same ms, increment to guarantee
        // strict ordering and eliminate intra-millisecond collision on the timestamp portion.
        // Note: this does not cap forward drift. If the system clock jumps ahead and then
        // corrects, the guard holds the inflated timestamp until wall-clock time catches up.
        // This preserves ordering guarantees at the cost of timestamps appearing ahead of
        // real time during the catch-up window.
        if ($now <= $this->lastTimestamp) {
            $now = $this->lastTimestamp + 1;
        }

        $random = self::randomBase62($config['random']);

        if ($this->blind) {
            $hmacInput = pack('J', $now) . ($config['node'] > 0 ? $this->node : '');
            $hmacHex = hash_hmac('sha256', $hmacInput, $this->blindSecret);
            $opaqueLen = $config['ts'] + $config['node'];
            $hmacValue = hexdec(substr($hmacHex, 0, 15)) % (62 ** $opaqueLen);
            $opaquePrefix = self::encodeBase62($hmacValue, $opaqueLen);
            $id = $opaquePrefix . $random;
        } else {
            $timestamp = self::encodeBase62($now, $config['ts']);
            $id = $config['node'] > 0
                ? $timestamp . $this->node . $random
                : $timestamp . $random;
        }

        // Only update after successful generation to prevent counter desync on failure.
        $this->lastTimestamp = $now;
        $fullId = self::applyPrefix($id, $prefix);

        if ($this->maxIdLength !== null && strlen($fullId) > $this->maxIdLength) {
            throw new IdOverflowException(
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
     * Generate a random 2-char node identifier.
     *
     * Uses cryptographically secure random bytes to produce one of 3,844
     * possible values (62^2). This is a dev/testing fallback — production
     * deployments should always set an explicit node to guarantee uniqueness.
     *
     * Modulo bias: 65536 % 3844 = 120 → values [0, 119] are ~0.003% more
     * likely. Negligible for a non-deterministic fallback.
     */
    private static function autoDetectNode(): string
    {
        $bytes = random_bytes(2);
        $nodeNum = (ord($bytes[0]) << 8 | ord($bytes[1])) % 3844;

        return self::encodeBase62($nodeNum, 2);
    }

    /**
     * Encode an integer to base62 string with fixed length.
     *
     * @since 4.0.0
     */
    public static function encodeBase62(int $num, int $length): string
    {
        if ($num < 0) {
            throw new IdOverflowException('Cannot encode negative value');
        }

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
            throw new IdOverflowException(
                sprintf('Value exceeds maximum for %d base62 characters', $length),
            );
        }

        return $encoded;
    }

    /**
     * Decode a base62 string to integer.
     *
     * @since 4.0.0
     */
    public static function decodeBase62(string $str): int
    {
        if ($str === '') {
            throw new InvalidIdException('Cannot decode empty string');
        }

        // Early guard: strip leading zeros to count significant digits.
        // 62^11 > PHP_INT_MAX on 64-bit, so more than 11 significant base62
        // digits always overflows. Strings of exactly 11 significant chars
        // may or may not overflow, so the arithmetic check handles those.
        $significant = ltrim($str, '0');
        if ($significant !== '' && strlen($significant) > 11) {
            throw new IdOverflowException('Value exceeds 64-bit integer range');
        }

        $result = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $pos = self::BASE62_MAP[$str[$i]] ?? null;

            if ($pos === null) {
                throw new InvalidIdException("Invalid base62 character: {$str[$i]}");
            }

            // Check for overflow before performing the operation.
            // This avoids relying on is_float() which may not catch all
            // overflow scenarios (e.g. silent wrap to negative on some builds).
            if ($result > intdiv(PHP_INT_MAX - $pos, 62)) {
                throw new IdOverflowException('Value exceeds 64-bit integer range');
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
            throw new InvalidIdException('Invalid HybridId format');
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

    private static function isValidPrefix(string $prefix): bool
    {
        return $prefix !== ''
            && strlen($prefix) <= self::PREFIX_MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9]*$/', $prefix) === 1;
    }

    private static function validatePrefix(string $prefix): void
    {
        if (!self::isValidPrefix($prefix)) {
            throw new InvalidPrefixException(
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
