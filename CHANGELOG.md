# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [4.1.2] - 2026-02-17

### Fixed
- `readEnv()` now falls back to `$_ENV` and `$_SERVER` superglobals, fixing compatibility with vlucas/phpdotenv v5+ default config (`createImmutable()`) which does not call `putenv()` (#182)

### Documentation
- `fromEnv()` docblock now documents read order: `getenv()` → `$_ENV` → `$_SERVER`

## [4.1.1] - 2026-02-17

### Added
- `HYBRID_ID_MAX_LENGTH` env var support in `fromEnv()` (#180)
- `maxDriftMs` constructor parameter to tune monotonic drift cap (default: 5000ms) (#174)
- `DEFAULT_MAX_DRIFT_MS` public constant
- `MockHybridIdGenerator` moved from `tests/Testing/` to `src/Testing/` — now available to consumers via `HybridId\Testing\MockHybridIdGenerator`

## [4.1.0] - 2026-02-17

### Added
- `blindSecret` constructor parameter for external key injection, rotation, and multi-instance coordination (#162)
- `HYBRID_ID_BLIND_SECRET` env var support (base64-encoded) in `fromEnv()` (#162)
- `HybridIdGenerator::BASE62` constant promoted to public (#168)
- `#[\Override]` attributes on all interface implementations (#170)
- Blind mode + `maxIdLength` constraint tests (#169)

### Changed
- Blind mode HMAC upgraded from SHA-256 to SHA-384 with per-character generation, reducing modulo bias from ~37% to ~0.003% (#161)
- Batch count limit aligned to 10,000 across `IdGenerator` interface, `HybridIdGenerator`, and CLI (#164)
- `fromEnv()` exception messages now truncate env var values to 20 chars (#166)
- Passing `blindSecret` implicitly enables blind mode without requiring `blind: true`

### Fixed
- `safeHexdec()` now validates hex string length before `hexdec()` call (#165)

### Documentation
- `fromUUIDv4Format()`: added `@warning` about silent timestamp fallback when `$timestampMs` is null (#167)
- Blind mode: documented ordering analysis limitation — sequential IDs reveal relative generation order (#163)
- `parse()`: clarified that prefix/body are returned for debugging when `valid` is false (#171)
- `encodeBase62()`/`decodeBase62()`: replaced contradictory `@internal` with `@api` (#172)

## [4.0.0] - 2026-02-17

### Added
- Profile enum (compact, standard, extended)
- Custom exception hierarchy: HybridIdException interface, IdOverflowException, InvalidIdException, InvalidPrefixException, InvalidProfileException, NodeRequiredException
- ProfileRegistry and ProfileRegistryInterface for injectable dependency with custom profiles
- UuidConverter class with toUUIDv8()/fromUUIDv8() (lossless), toUUIDv7()/fromUUIDv7() (timestamp-preserving), toUUIDv4Format()/fromUUIDv4Format() (lossy, v4 structure)
- generateBatch() method for batch ID generation (max 10,000)
- MockHybridIdGenerator for testing (in tests/Testing/)
- fromEnv() factory method with standardized env var reading
- Blind mode (blind: true) with HMAC-SHA256 hashed timestamps for privacy
- Monotonic drift cap (MAX_DRIFT_MS = 5000) to prevent unbounded future timestamps
- secureRandomBytes() wrapper that converts CSPRNG failures to RuntimeException
- parse() now returns consistent key structure (all keys always present, null when invalid)

### Changed
- BREAKING: requireExplicitNode defaults to true (production must set explicit node)
- BREAKING: Compact profile drops node field (8ts + 8rand instead of 8ts + 2node + 6rand) for 47.6 bits entropy vs 35.7
- BREAKING: IdGenerator interface now requires generateBatch()
- BREAKING: Node auto-detection uses random_bytes(2) instead of crc32(hostname:pid)
- BREAKING: toUUIDv4()/fromUUIDv4() renamed to toUUIDv4Format()/fromUUIDv4Format()
- UUID to*() methods reject prefixed IDs (must strip prefix first)
- fromUUIDv4Format() validates timestamp bounds (non-negative, max 62^8-1)
- Constructor node validation throws InvalidIdException instead of \InvalidArgumentException
- Exception messages no longer enumerate valid profile names (security: prevents leaking custom profile names)
- Batch limit reduced from 100,000 to 10,000
- Deprecation warnings include migration examples and global registry notes

### Deprecated
- registerProfile() (use ProfileRegistry injection instead, unsafe in multi-tenant/long-lived processes)
- resetProfiles() (use fresh ProfileRegistry instance instead)

### Removed
- MockHybridIdGenerator from production autoload (moved to tests/Testing/)

### Security
- decodeBase62() overflow detection rewritten (replaced unreliable is_float())
- Monotonic guard capped at 5000ms drift
- UUID conversion rejects prefixed IDs to prevent type confusion
- Exception messages no longer leak custom profile names
- random_bytes() failures wrapped in domain exception
- Global registry risks documented for multi-tenant environments
- fromUUIDv4Format() timestamp bounds validation prevents sort-order manipulation

## [3.2.2] - 2026-02-16

### Added
- PHP 8.5 to CI matrix

### Changed
- Update README requirements section to reflect PHP 8.3, 8.4, and 8.5 support

## [3.2.1] - 2026-02-16

### Added
- Laravel and Doctrine integration sections to README
- Improved Packagist SEO with better keywords and description

## [3.2.0] - 2026-02-16

### Added
- Enhanced compare() with strict total ordering and tiebreaker on body (compatible with usort())
- Range helpers: minForTimestamp() and maxForTimestamp() for time-range queries
- Production node guard: requireExplicitNode constructor parameter (defaults to false in v3)
- Expanded documentation on database usage, collision probability, and framework integrations

## [3.1.1] - 2026-02-16

### Fixed
- CLI input validation improvements
- Broaden error handling for edge cases
- Documentation corrections and clarifications

## [3.1.0] - 2026-02-16

### Added
- Code quality improvements across codebase
- Security hardening measures
- Test coverage improvements

## [3.0.0] - 2026-02-16

### Added
- bodyLength() method promoted to IdGenerator interface
- validate() method promoted to IdGenerator interface
- CLI refactored to HybridId\Cli\Application class with testable OOP architecture

### Changed
- BREAKING: IdGenerator interface now requires bodyLength() and validate() methods
- CLI exit codes fixed (errors now return exit code 1 instead of 0)
- CLI-only guard: bin/hybrid-id rejects non-CLI SAPI execution
- --count validation now uses filter_var(FILTER_VALIDATE_INT) instead of (int) cast

## [2.2.0] - 2026-02-16

### Added
- bodyLength() method for retrieving ID body length
- validate() method for profile-aware ID validation with optional prefix checking
- parse() method for extracting all ID components in a single call
- recommendedColumnSize() helper for database column sizing
- maxIdLength() getter for retrieving configured length limit

## [2.1.0] - 2026-02-15

### Added
- Code quality improvements across codebase
- Security hardening measures
- Test coverage improvements

## [2.0.1] - 2026-02-15

### Fixed
- Fix modulo bias in random number generation
- Fix extractPrefix edge case handling
- Fix CLI sanitization issues
- Fix profile upper bound validation
- Document collision probability
- Document monotonic drift behavior
- Update constraints documentation

## [2.0.0] - 2026-02-15

### Added
- Instance-based HybridIdGenerator replacing static HybridId class
- Stripe-style prefix support for self-documenting IDs
- compare() method for chronological sorting
- Custom profile registration support
- Profile-specific generator methods (compact(), standard(), extended())

### Changed
- BREAKING: Replace static HybridId with instance-based HybridIdGenerator
- Optimize randomBase62 with single random_bytes() call

### Removed
- BREAKING: Static HybridId class

## [1.4.1] - 2026-02-14

### Fixed
- Fix Packagist auth: use query parameters instead of HTTP Basic Auth

## [1.4.0] - 2026-02-14

### Added
- Overflow guard tests for encodeBase62()
- Final polish for overflow guard
- CLI validation improvements
- Test reliability enhancements

### Fixed
- Closes issues #36-#41

## [1.3.0] - 2026-02-14

### Changed
- Refine sanitization logic
- Refine monotonic guard behavior
- Refine encoding implementation
- CI reliability improvements

### Fixed
- Closes issues #26-#34

## [1.2.0] - 2026-02-14

### Added
- Harden CI pipeline
- Environment configuration improvements
- Input validation hardening

### Fixed
- Closes issues #16-#24

## [1.1.2] - 2026-02-14

### Added
- Packagist auto-update via GitHub Actions

## [1.1.1] - 2026-02-14

### Changed
- BREAKING: Drop PHP 8.2 support
- Require PHP 8.3 or higher

## [1.1.0] - 2026-02-14

### Added
- Security documentation

### Fixed
- Performance improvements
- CLI robustness enhancements
- Monotonic guard fixes

## [1.0.0] - 2026-02-14

### Added
- Initial release
- HybridId generator with three profiles: compact (16), standard (20), extended (24)
- Base62 encoding for URL-safe, human-readable IDs
- Monotonic timestamp support for chronological ordering
- Node support for multi-node collision prevention
- CLI tool (bin/hybrid-id) for ID generation and inspection
- Static utility methods for validation and metadata extraction
- Configurable entropy profiles
- Time-sortable IDs with millisecond precision

[4.1.2]: https://github.com/alesitom/hybrid-id/compare/v4.1.1...v4.1.2
[4.1.1]: https://github.com/alesitom/hybrid-id/compare/v4.1.0...v4.1.1
[4.1.0]: https://github.com/alesitom/hybrid-id/compare/v4.0.0...v4.1.0
[4.0.0]: https://github.com/alesitom/hybrid-id/compare/v3.2.2...v4.0.0
[3.2.2]: https://github.com/alesitom/hybrid-id/compare/v3.2.1...v3.2.2
[3.2.1]: https://github.com/alesitom/hybrid-id/compare/v3.2.0...v3.2.1
[3.2.0]: https://github.com/alesitom/hybrid-id/compare/v3.1.1...v3.2.0
[3.1.1]: https://github.com/alesitom/hybrid-id/compare/v3.1.0...v3.1.1
[3.1.0]: https://github.com/alesitom/hybrid-id/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/alesitom/hybrid-id/compare/v2.2.0...v3.0.0
[2.2.0]: https://github.com/alesitom/hybrid-id/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/alesitom/hybrid-id/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/alesitom/hybrid-id/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/alesitom/hybrid-id/compare/v1.4.1...v2.0.0
[1.4.1]: https://github.com/alesitom/hybrid-id/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/alesitom/hybrid-id/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/alesitom/hybrid-id/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/alesitom/hybrid-id/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/alesitom/hybrid-id/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/alesitom/hybrid-id/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/alesitom/hybrid-id/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/alesitom/hybrid-id/releases/tag/v1.0.0
