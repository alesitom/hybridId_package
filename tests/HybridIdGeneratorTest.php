<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\Exception\IdOverflowException;
use HybridId\Exception\InvalidIdException;
use HybridId\Exception\InvalidPrefixException;
use HybridId\Exception\InvalidProfileException;
use HybridId\Exception\NodeRequiredException;
use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;
use HybridId\Profile;
use PHPUnit\Framework\TestCase;

final class HybridIdGeneratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface
    // -------------------------------------------------------------------------

    public function testImplementsIdGenerator(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertInstanceOf(IdGenerator::class, $gen);
    }

    // -------------------------------------------------------------------------
    // Generation
    // -------------------------------------------------------------------------

    public function testGenerateReturnsDefaultProfileLength(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(20, strlen($gen->generate()));
    }

    public function testCompactReturns16Chars(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(16, strlen($gen->compact()));
    }

    public function testStandardReturns20Chars(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(20, strlen($gen->standard()));
    }

    public function testExtendedReturns24Chars(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(24, strlen($gen->extended()));
    }

    public function testGenerateUsesConfiguredProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');

        $this->assertSame(16, strlen($gen->generate()));
    }

    public function testGenerateContainsOnlyBase62Characters(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{20}$/', $gen->generate());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{16}$/', $gen->compact());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $gen->extended());
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $ids = [];

        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $gen->generate();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    public function testCompactProducesUniqueIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $ids = [];

        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $gen->compact();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorAcceptsAllProfiles(): void
    {
        $compact = new HybridIdGenerator(profile: 'compact');
        $standard = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $extended = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $this->assertSame('compact', $compact->getProfile());
        $this->assertSame('standard', $standard->getProfile());
        $this->assertSame('extended', $extended->getProfile());
    }

    public function testConstructorRejectsInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile');

        new HybridIdGenerator(profile: 'ultra', node: 'T1');
    }

    public function testConstructorSetsNode(): void
    {
        $gen = new HybridIdGenerator(node: 'X9');

        $this->assertSame('X9', $gen->getNode());
        $this->assertSame('X9', HybridIdGenerator::extractNode($gen->generate()));
    }

    public function testConstructorRejectsInvalidNodeLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        new HybridIdGenerator(node: 'ABC');
    }

    public function testConstructorRejectsNonBase62Node(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HybridIdGenerator(node: '!@');
    }

    public function testConstructorRejectsEmptyNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        new HybridIdGenerator(node: '');
    }

    public function testConstructorRejectsSingleCharNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        new HybridIdGenerator(node: 'A');
    }

    public function testConstructorAutoDetectsNode(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(2, strlen($gen->getNode()));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{2}$/', $gen->getNode());
    }

    // -------------------------------------------------------------------------
    // Multiple instances
    // -------------------------------------------------------------------------

    public function testMultipleInstancesHaveIndependentState(): void
    {
        $gen1 = new HybridIdGenerator(profile: 'compact', node: 'A1');
        $gen2 = new HybridIdGenerator(profile: 'extended', node: 'B2');

        $id1 = $gen1->generate();
        $id2 = $gen2->generate();

        $this->assertSame(16, strlen($id1));
        $this->assertSame(24, strlen($id2));
        $this->assertNull(HybridIdGenerator::extractNode($id1)); // compact has no node
        $this->assertSame('B2', HybridIdGenerator::extractNode($id2));
    }

    public function testIndependentMonotonicGuards(): void
    {
        $gen1 = new HybridIdGenerator(requireExplicitNode: false);
        $gen2 = new HybridIdGenerator(requireExplicitNode: false);

        // Generate many IDs on gen1 to advance its monotonic counter
        for ($i = 0; $i < 50; $i++) {
            $gen1->generate();
        }

        $ts1 = HybridIdGenerator::extractTimestamp($gen1->generate());
        $ts2 = HybridIdGenerator::extractTimestamp($gen2->generate());

        // gen1's timestamp should be significantly ahead due to monotonic increments
        // gen2 should be close to real time
        $this->assertGreaterThan($ts2, $ts1);
    }

    public function testMonotonicDriftThrowsWhenExceeded(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        // Generate enough IDs to push drift beyond 5000ms.
        // Each call within the same ms increments by 1, so we need >5000 rapid calls.
        $this->expectException(IdOverflowException::class);
        $this->expectExceptionMessage('Monotonic timestamp drift exceeds');

        for ($i = 0; $i < 10_000; $i++) {
            $gen->generate();
        }
    }

    public function testMonotonicDriftAllowsModerateRate(): void
    {
        // A few hundred rapid calls should stay well within the 5000ms drift limit
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        for ($i = 0; $i < 200; $i++) {
            $id = $gen->generate();
        }

        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    // -------------------------------------------------------------------------
    // Validation (static)
    // -------------------------------------------------------------------------

    public function testIsValidAcceptsAllProfiles(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertTrue(HybridIdGenerator::isValid($gen->compact()));
        $this->assertTrue(HybridIdGenerator::isValid($gen->standard()));
        $this->assertTrue(HybridIdGenerator::isValid($gen->extended()));
    }

    public function testIsValidRejectsWrongLengths(): void
    {
        $this->assertFalse(HybridIdGenerator::isValid(''));
        $this->assertFalse(HybridIdGenerator::isValid('abc'));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('A', 15)));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('A', 17)));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('A', 19)));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('A', 21)));
    }

    public function testIsValidRejectsNonBase62Characters(): void
    {
        $this->assertFalse(HybridIdGenerator::isValid('ABCDEFGH!@#$%^&*'));
        $this->assertFalse(HybridIdGenerator::isValid('ABCDEFGH12345678-_+='));
    }

    // -------------------------------------------------------------------------
    // Extraction (static)
    // -------------------------------------------------------------------------

    public function testExtractTimestampReturnsReasonableValue(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();
        $after = (int) (microtime(true) * 1000);

        $timestamp = HybridIdGenerator::extractTimestamp($id);

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testExtractTimestampWorksForAllProfiles(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $tsCompact = HybridIdGenerator::extractTimestamp($gen->compact());
        $tsStandard = HybridIdGenerator::extractTimestamp($gen->standard());
        $tsExtended = HybridIdGenerator::extractTimestamp($gen->extended());

        // Monotonic guard may increment beyond real time, allow small drift
        $this->assertGreaterThanOrEqual($before, $tsCompact);
        $this->assertLessThan($tsStandard, $tsCompact);
        $this->assertLessThan($tsExtended, $tsStandard);
    }

    public function testExtractTimestampThrowsOnInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridIdGenerator::extractTimestamp('invalid');
    }

    public function testExtractDateTimeReturnsCurrentTime(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();
        $dt = HybridIdGenerator::extractDateTime($id);
        $now = new \DateTimeImmutable();

        $diff = abs($now->getTimestamp() - $dt->getTimestamp());

        $this->assertLessThan(2, $diff);
    }

    public function testExtractNodeReturnsConfiguredNode(): void
    {
        $gen = new HybridIdGenerator(node: 'N1');

        $this->assertNull(HybridIdGenerator::extractNode($gen->compact())); // compact has no node
        $this->assertSame('N1', HybridIdGenerator::extractNode($gen->standard()));
        $this->assertSame('N1', HybridIdGenerator::extractNode($gen->extended()));
    }

    public function testExtractNodeAutoDetectsConsistently(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id1 = $gen->generate();
        $id2 = $gen->generate();

        $this->assertSame(
            HybridIdGenerator::extractNode($id1),
            HybridIdGenerator::extractNode($id2),
        );
    }

    // -------------------------------------------------------------------------
    // Profile detection (static)
    // -------------------------------------------------------------------------

    public function testDetectProfileIdentifiesCorrectly(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame('compact', HybridIdGenerator::detectProfile($gen->compact()));
        $this->assertSame('standard', HybridIdGenerator::detectProfile($gen->standard()));
        $this->assertSame('extended', HybridIdGenerator::detectProfile($gen->extended()));
    }

    public function testDetectProfileReturnsNullForInvalid(): void
    {
        $this->assertNull(HybridIdGenerator::detectProfile('invalid'));
        $this->assertNull(HybridIdGenerator::detectProfile(''));
    }

    // -------------------------------------------------------------------------
    // Entropy (static)
    // -------------------------------------------------------------------------

    public function testEntropyReturnsCorrectBits(): void
    {
        $this->assertSame(47.6, HybridIdGenerator::entropy('compact'));
        $this->assertSame(59.5, HybridIdGenerator::entropy('standard'));
        $this->assertSame(83.4, HybridIdGenerator::entropy('extended'));
    }

    public function testEntropyThrowsOnInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridIdGenerator::entropy('nonexistent');
    }

    // -------------------------------------------------------------------------
    // Chronological ordering
    // -------------------------------------------------------------------------

    public function testIdsAreChronologicallyOrdered(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id1 = $gen->generate();
        usleep(2000); // 2ms
        $id2 = $gen->generate();

        $this->assertLessThanOrEqual(
            HybridIdGenerator::extractTimestamp($id2),
            HybridIdGenerator::extractTimestamp($id1),
        );
    }

    // -------------------------------------------------------------------------
    // Monotonic guard
    // -------------------------------------------------------------------------

    public function testTimestampNeverDecreases(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $timestamps = [];

        for ($i = 0; $i < 100; $i++) {
            $timestamps[] = HybridIdGenerator::extractTimestamp($gen->generate());
        }

        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThanOrEqual($timestamps[$i - 1], $timestamps[$i]);
        }
    }

    public function testTimestampStrictlyIncrementsWithinSameMillisecond(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $timestamps = [];

        for ($i = 0; $i < 50; $i++) {
            $timestamps[] = HybridIdGenerator::extractTimestamp($gen->generate());
        }

        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThan(
                $timestamps[$i - 1],
                $timestamps[$i],
                'Timestamps must strictly increase even within the same millisecond',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Profile info (static)
    // -------------------------------------------------------------------------

    public function testProfilesReturnsAllNames(): void
    {
        $this->assertSame(['compact', 'standard', 'extended'], HybridIdGenerator::profiles());
    }

    public function testProfileConfigReturnsCorrectData(): void
    {
        $config = HybridIdGenerator::profileConfig('compact');

        $this->assertSame(16, $config['length']);
        $this->assertSame(8, $config['ts']);
        $this->assertSame(0, $config['node']);
        $this->assertSame(8, $config['random']);
    }

    public function testProfileConfigThrowsOnInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridIdGenerator::profileConfig('nonexistent');
    }

    // -------------------------------------------------------------------------
    // fromEnv
    // -------------------------------------------------------------------------

    public function testFromEnvReadsProfile(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=compact');
            putenv('HYBRID_ID_NODE=');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame('compact', $gen->getProfile());
            $this->assertSame(16, strlen($gen->generate()));
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvReadsNode(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=');
            putenv('HYBRID_ID_NODE=Z3');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame('Z3', $gen->getNode());
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvReadsBoth(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=extended');
            putenv('HYBRID_ID_NODE=Q7');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame(24, strlen($gen->generate()));
            $this->assertSame('Q7', HybridIdGenerator::extractNode($gen->generate()));
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvDefaultsWhenUnset(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE=T1');
            putenv('HYBRID_ID_REQUIRE_NODE');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame('standard', $gen->getProfile());
            $this->assertSame('T1', $gen->getNode());
            $this->assertSame(20, strlen($gen->generate()));
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');
        }
    }

    public function testFromEnvThrowsWithoutNodeByDefault(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');

            $this->expectException(NodeRequiredException::class);

            HybridIdGenerator::fromEnv();
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');
        }
    }

    public function testFromEnvRejectsInvalidProfile(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=../../etc/passwd');
            putenv('HYBRID_ID_NODE');

            $this->expectException(InvalidProfileException::class);
            $this->expectExceptionMessage('Invalid HYBRID_ID_PROFILE');

            HybridIdGenerator::fromEnv();
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvRejectsInvalidNode(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE=<script>');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid HYBRID_ID_NODE');

            HybridIdGenerator::fromEnv();
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvRejectsOversizedNode(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE=' . str_repeat('A', 100));

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid HYBRID_ID_NODE');

            HybridIdGenerator::fromEnv();
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    // -------------------------------------------------------------------------
    // Prefix support
    // -------------------------------------------------------------------------

    public function testGenerateWithPrefixFormatsCorrectly(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');

        $this->assertStringStartsWith('usr_', $id);
        $this->assertSame(24, strlen($id)); // 3 prefix + 1 underscore + 20 standard
    }

    public function testAllProfilesWithPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $compact = $gen->compact('log');
        $standard = $gen->standard('usr');
        $extended = $gen->extended('txn');

        $this->assertStringStartsWith('log_', $compact);
        $this->assertSame(20, strlen($compact));

        $this->assertStringStartsWith('usr_', $standard);
        $this->assertSame(24, strlen($standard));

        $this->assertStringStartsWith('txn_', $extended);
        $this->assertSame(28, strlen($extended));
    }

    public function testGenerateWithoutPrefixIsUnchanged(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();

        $this->assertSame(20, strlen($id));
        $this->assertStringNotContainsString('_', $id);
    }

    public function testGenerateWithNullPrefixIsUnchanged(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate(null);

        $this->assertSame(20, strlen($id));
        $this->assertStringNotContainsString('_', $id);
    }

    public function testPrefixedIdIsValid(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertTrue(HybridIdGenerator::isValid($gen->generate('usr')));
        $this->assertTrue(HybridIdGenerator::isValid($gen->compact('log')));
        $this->assertTrue(HybridIdGenerator::isValid($gen->extended('txn')));
    }

    public function testDetectProfileWithPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame('standard', HybridIdGenerator::detectProfile($gen->generate('usr')));
        $this->assertSame('compact', HybridIdGenerator::detectProfile($gen->compact('log')));
        $this->assertSame('extended', HybridIdGenerator::detectProfile($gen->extended('txn')));
    }

    public function testExtractTimestampWithPrefix(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');
        $after = (int) (microtime(true) * 1000);

        $timestamp = HybridIdGenerator::extractTimestamp($id);

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testExtractNodeWithPrefix(): void
    {
        $gen = new HybridIdGenerator(node: 'N1');
        $id = $gen->generate('usr');

        $this->assertSame('N1', HybridIdGenerator::extractNode($id));
    }

    public function testExtractDateTimeWithPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');
        $dt = HybridIdGenerator::extractDateTime($id);
        $now = new \DateTimeImmutable();

        $diff = abs($now->getTimestamp() - $dt->getTimestamp());

        $this->assertLessThan(2, $diff);
    }

    public function testExtractPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame('usr', HybridIdGenerator::extractPrefix($gen->generate('usr')));
        $this->assertSame('log', HybridIdGenerator::extractPrefix($gen->compact('log')));
        $this->assertNull(HybridIdGenerator::extractPrefix($gen->generate()));
    }

    public function testExtractPrefixReturnsNullForLeadingUnderscore(): void
    {
        $this->assertNull(HybridIdGenerator::extractPrefix('_ABCDEFGHIJ1234567890'));
    }

    public function testPrefixValidationRejectsEmpty(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('');
    }

    public function testPrefixValidationRejectsUppercase(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('USR');
    }

    public function testPrefixValidationRejectsSpecialChars(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('usr!');
    }

    public function testPrefixValidationRejectsTooLong(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('abcdefghi'); // 9 chars, max is 8
    }

    public function testPrefixValidationRejectsStartingWithDigit(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('1usr');
    }

    public function testPrefixMaxLengthIsAccepted(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('abcdefgh'); // exactly 8 chars

        $this->assertStringStartsWith('abcdefgh_', $id);
        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    public function testPrefixWithDigitsAfterFirstChar(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr2');

        $this->assertStringStartsWith('usr2_', $id);
        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    public function testIsValidRejectsInvalidPrefixFormat(): void
    {
        $this->assertFalse(HybridIdGenerator::isValid('USR_' . str_repeat('A', 20)));
        $this->assertFalse(HybridIdGenerator::isValid('1usr_' . str_repeat('A', 20)));
        $this->assertFalse(HybridIdGenerator::isValid('abcdefghi_' . str_repeat('A', 20)));
        $this->assertFalse(HybridIdGenerator::isValid('_' . str_repeat('A', 20)));
        $this->assertFalse(HybridIdGenerator::isValid('usr__' . str_repeat('A', 20)));
        $this->assertFalse(HybridIdGenerator::isValid('a_b_' . str_repeat('A', 20)));
    }

    public function testPrefixedIdsAreUnique(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $ids[] = $gen->generate('usr');
        }

        $this->assertCount(100, array_unique($ids));
    }

    // -------------------------------------------------------------------------
    // Compare
    // -------------------------------------------------------------------------

    public function testCompareReturnsCorrectOrder(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id1 = $gen->generate();
        usleep(2000);
        $id2 = $gen->generate();

        $this->assertSame(-1, HybridIdGenerator::compare($id1, $id2));
        $this->assertSame(1, HybridIdGenerator::compare($id2, $id1));
    }

    public function testCompareWorksWithUsort(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $ids = [];

        for ($i = 0; $i < 20; $i++) {
            $ids[] = $gen->generate();
        }

        $shuffled = $ids;
        shuffle($shuffled);

        usort($shuffled, HybridIdGenerator::compare(...));

        $this->assertSame($ids, $shuffled);
    }

    public function testCompareHandlesPrefixedIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id1 = $gen->generate('usr');
        usleep(2000);
        $id2 = $gen->generate('ord');

        $this->assertSame(-1, HybridIdGenerator::compare($id1, $id2));
    }

    public function testCompareAcrossProfiles(): void
    {
        $genCompact = new HybridIdGenerator(profile: 'compact');
        $genExtended = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $earlier = $genCompact->generate();
        usleep(2000);
        $later = $genExtended->generate();

        $this->assertSame(-1, HybridIdGenerator::compare($earlier, $later));
        $this->assertSame(1, HybridIdGenerator::compare($later, $earlier));
    }

    public function testCompareAcrossProfilesWithPrefixes(): void
    {
        $genCompact = new HybridIdGenerator(profile: 'compact');
        $genExtended = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $earlier = $genCompact->generate('log');
        usleep(2000);
        $later = $genExtended->generate('txn');

        $this->assertSame(-1, HybridIdGenerator::compare($earlier, $later));
    }

    public function testCompareIdenticalIdReturnsZero(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();

        $this->assertSame(0, HybridIdGenerator::compare($id, $id));
    }

    public function testCompareSameTimestampDistinctIdsReturnsNonZero(): void
    {
        // Generate many IDs rapidly — monotonic guard increments timestamps,
        // but different random portions ensure distinct IDs
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $gen->generate();
        }

        // All consecutive pairs should be distinct and ordered
        for ($i = 1; $i < count($ids); $i++) {
            $this->assertNotSame(0, HybridIdGenerator::compare($ids[$i - 1], $ids[$i]));
            $this->assertSame(-1, HybridIdGenerator::compare($ids[$i - 1], $ids[$i]));
        }
    }

    // -------------------------------------------------------------------------
    // Custom profiles
    // -------------------------------------------------------------------------

    public function testRegisterProfileAndGenerate(): void
    {
        try {
            HybridIdGenerator::registerProfile('ultra', 22);

            $gen = new HybridIdGenerator(profile: 'ultra', node: 'T1');
            $id = $gen->generate();

            $this->assertSame(32, strlen($id)); // 8ts + 2node + 22random
            $this->assertTrue(HybridIdGenerator::isValid($id));
            $this->assertSame('ultra', HybridIdGenerator::detectProfile($id));
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testCustomProfileEntropy(): void
    {
        try {
            HybridIdGenerator::registerProfile('mega', 30);

            $this->assertSame(178.6, HybridIdGenerator::entropy('mega'));
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testCustomProfileConfig(): void
    {
        try {
            HybridIdGenerator::registerProfile('tiny', 8);

            $config = HybridIdGenerator::profileConfig('tiny');

            $this->assertSame(18, $config['length']);
            $this->assertSame(8, $config['ts']);
            $this->assertSame(2, $config['node']);
            $this->assertSame(8, $config['random']);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testCustomProfileAppearsInProfiles(): void
    {
        try {
            HybridIdGenerator::registerProfile('ultra', 22);

            $profiles = HybridIdGenerator::profiles();

            $this->assertContains('ultra', $profiles);
            $this->assertContains('compact', $profiles);
            $this->assertContains('standard', $profiles);
            $this->assertContains('extended', $profiles);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testCustomProfileWithPrefix(): void
    {
        try {
            HybridIdGenerator::registerProfile('ultra', 22);

            $gen = new HybridIdGenerator(profile: 'ultra', node: 'T1');
            $id = $gen->generate('usr');

            $this->assertStringStartsWith('usr_', $id);
            $this->assertSame(36, strlen($id)); // 3 + 1 + 32
            $this->assertTrue(HybridIdGenerator::isValid($id));
            $this->assertSame('ultra', HybridIdGenerator::detectProfile($id));
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testRegisterProfileRejectsDuplicateName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        HybridIdGenerator::registerProfile('compact', 10);
    }

    public function testRegisterProfileRejectsLengthConflict(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('conflicts with');

            // standard is 20 = 10 + 10 random, so random=10 would conflict
            HybridIdGenerator::registerProfile('myprofile', 10);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testRegisterProfileRejectsZeroRandom(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('between 6 and 128');

            HybridIdGenerator::registerProfile('norandom', 0);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testRegisterProfileRejectsExcessiveRandom(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('between 6 and 128');

            HybridIdGenerator::registerProfile('huge', 129);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testRegisterProfileRejectsInsufficientRandom(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('between 6 and 128');

            HybridIdGenerator::registerProfile('weak', 5);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testRegisterProfileRejectsInvalidName(): void
    {
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('lowercase alphanumeric');

            HybridIdGenerator::registerProfile('My-Profile', 10);
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    public function testResetProfilesClearsCustom(): void
    {
        HybridIdGenerator::registerProfile('temp', 18);
        HybridIdGenerator::resetProfiles();

        $this->assertNotContains('temp', HybridIdGenerator::profiles());
        $this->assertSame(['compact', 'standard', 'extended'], HybridIdGenerator::profiles());
    }

    // -------------------------------------------------------------------------
    // bodyLength (#78)
    // -------------------------------------------------------------------------

    public function testBodyLengthReturnsCorrectValues(): void
    {
        $this->assertSame(16, (new HybridIdGenerator(profile: 'compact'))->bodyLength());
        $this->assertSame(20, (new HybridIdGenerator(profile: 'standard', node: 'T1'))->bodyLength());
        $this->assertSame(24, (new HybridIdGenerator(profile: 'extended', node: 'T1'))->bodyLength());
    }

    public function testBodyLengthWithCustomProfile(): void
    {
        try {
            HybridIdGenerator::registerProfile('ultra', 22);

            $this->assertSame(32, (new HybridIdGenerator(profile: 'ultra', node: 'T1'))->bodyLength());
        } finally {
            HybridIdGenerator::resetProfiles();
        }
    }

    // -------------------------------------------------------------------------
    // recommendedColumnSize (#78)
    // -------------------------------------------------------------------------

    public function testRecommendedColumnSizeWithoutPrefix(): void
    {
        $this->assertSame(16, HybridIdGenerator::recommendedColumnSize('compact'));
        $this->assertSame(20, HybridIdGenerator::recommendedColumnSize('standard'));
        $this->assertSame(24, HybridIdGenerator::recommendedColumnSize('extended'));
    }

    public function testRecommendedColumnSizeWithPrefix(): void
    {
        // prefix + 1 underscore + body
        $this->assertSame(20, HybridIdGenerator::recommendedColumnSize('compact', 3));    // 3+1+16
        $this->assertSame(25, HybridIdGenerator::recommendedColumnSize('compact', 8));    // 8+1+16
        $this->assertSame(28, HybridIdGenerator::recommendedColumnSize('standard', 7));   // 7+1+20
        $this->assertSame(29, HybridIdGenerator::recommendedColumnSize('standard', 8));   // 8+1+20
        $this->assertSame(33, HybridIdGenerator::recommendedColumnSize('extended', 8));   // 8+1+24
    }

    public function testRecommendedColumnSizeRejectsNegativePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxPrefixLength must be between 0 and 8');

        HybridIdGenerator::recommendedColumnSize('standard', -1);
    }

    public function testRecommendedColumnSizeRejectsTooLongPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxPrefixLength must be between 0 and 8');

        HybridIdGenerator::recommendedColumnSize('standard', 9);
    }

    public function testRecommendedColumnSizeRejectsInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile');

        HybridIdGenerator::recommendedColumnSize('nonexistent');
    }

    // -------------------------------------------------------------------------
    // maxIdLength (#81)
    // -------------------------------------------------------------------------

    public function testMaxIdLengthDefaultIsNull(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertNull($gen->getMaxIdLength());
    }

    public function testMaxIdLengthAcceptsValidValue(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1', maxIdLength: 32);

        $this->assertSame(32, $gen->getMaxIdLength());
    }

    public function testMaxIdLengthAcceptsExactBodyLength(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact', maxIdLength: 16);

        $this->assertSame(16, $gen->getMaxIdLength());
        // Unprefixed generation should work
        $this->assertSame(16, strlen($gen->generate()));
    }

    public function testMaxIdLengthRejectsBelowBodyLength(): void
    {
        $this->expectException(IdOverflowException::class);
        $this->expectExceptionMessage('maxIdLength (15) must be >= body length (16)');

        new HybridIdGenerator(profile: 'compact', maxIdLength: 15);
    }

    public function testMaxIdLengthAllowsWithinLimit(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1', maxIdLength: 32);

        // 7 prefix + 1 underscore + 24 body = 32 — exactly at limit
        $id = $gen->generate('billing');
        $this->assertSame(32, strlen($id));
    }

    public function testMaxIdLengthThrowsWhenExceeded(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1', maxIdLength: 32);

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('exceeds maxIdLength 32');

        // 8 prefix + 1 underscore + 24 body = 33 — exceeds 32
        $gen->generate('shipping');
    }

    public function testMaxIdLengthNullAllowsAnyLength(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1');

        // Max prefix (8) + underscore + 24 body = 33 — no limit set, should work
        $id = $gen->generate('abcdefgh');
        $this->assertSame(33, strlen($id));
    }

    public function testMaxIdLengthUnprefixedAlwaysFits(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', maxIdLength: 20, node: 'T1');

        // No prefix, body = 20, exactly at limit
        $id = $gen->generate();
        $this->assertSame(20, strlen($id));
    }

    // -------------------------------------------------------------------------
    // validate (#79)
    // -------------------------------------------------------------------------

    public function testValidateAcceptsCorrectProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'extended', node: 'T1');
        $id = $gen->generate();

        $this->assertTrue($gen->validate($id));
    }

    public function testValidateRejectsWrongProfile(): void
    {
        $genExtended = new HybridIdGenerator(profile: 'extended', node: 'T1');
        $genStandard = new HybridIdGenerator(profile: 'standard', node: 'T1');

        $standardId = $genStandard->generate();

        // Extended generator rejects standard-length ID
        $this->assertFalse($genExtended->validate($standardId));
    }

    public function testValidateAcceptsMatchingPrefix(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate('ord');

        $this->assertTrue($gen->validate($id, 'ord'));
    }

    public function testValidateRejectsMismatchedPrefix(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate('usr');

        $this->assertFalse($gen->validate($id, 'ord'));
    }

    public function testValidateAcceptsUnprefixedWhenNoPrefixExpected(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate();

        $this->assertTrue($gen->validate($id));
    }

    public function testValidateAcceptsPrefixedWhenNoPrefixExpected(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate('usr');

        // No expected prefix — accepts any valid prefix
        $this->assertTrue($gen->validate($id));
    }

    public function testValidateRejectsExpectedPrefixOnUnprefixedId(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $id = $gen->generate();

        // Expecting 'usr' but ID has no prefix
        $this->assertFalse($gen->validate($id, 'usr'));
    }

    public function testValidateRejectsEmpty(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertFalse($gen->validate(''));
    }

    public function testValidateRejectsInvalidCharacters(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertFalse($gen->validate('ABC!@#$%^&*()12345678'));
    }

    public function testValidateRejectsOversizedInput(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertFalse($gen->validate(str_repeat('A', 148)));
    }

    public function testValidateAllProfiles(): void
    {
        $compact = new HybridIdGenerator(profile: 'compact');
        $standard = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $extended = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $compactId = $compact->generate('log');
        $standardId = $standard->generate('usr');
        $extendedId = $extended->generate('txn');

        // Each validates its own profile
        $this->assertTrue($compact->validate($compactId, 'log'));
        $this->assertTrue($standard->validate($standardId, 'usr'));
        $this->assertTrue($extended->validate($extendedId, 'txn'));

        // Cross-profile validation fails
        $this->assertFalse($compact->validate($standardId));
        $this->assertFalse($standard->validate($extendedId));
        $this->assertFalse($extended->validate($compactId));
    }

    public function testValidateRejectsInvalidPrefixFormat(): void
    {
        $gen = new HybridIdGenerator(profile: 'standard', node: 'T1');

        $this->assertFalse($gen->validate('USR_' . str_repeat('A', 20)));
        $this->assertFalse($gen->validate('1usr_' . str_repeat('A', 20)));
    }

    // -------------------------------------------------------------------------
    // parse (#80)
    // -------------------------------------------------------------------------

    public function testParseValidUnprefixedId(): void
    {
        $gen = new HybridIdGenerator(node: 'A1');
        $id = $gen->generate();

        $result = HybridIdGenerator::parse($id);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['prefix']);
        $this->assertSame('standard', $result['profile']);
        $this->assertSame($id, $result['body']);
        $this->assertIsInt($result['timestamp']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result['datetime']);
        $this->assertSame('A1', $result['node']);
        $this->assertSame(10, strlen($result['random']));
    }

    public function testParseValidPrefixedId(): void
    {
        $gen = new HybridIdGenerator(node: 'B2');
        $id = $gen->generate('usr');

        $result = HybridIdGenerator::parse($id);

        $this->assertTrue($result['valid']);
        $this->assertSame('usr', $result['prefix']);
        $this->assertSame('standard', $result['profile']);
        $this->assertSame(20, strlen($result['body']));
        $this->assertSame('B2', $result['node']);
    }

    public function testParseAllProfiles(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $compact = HybridIdGenerator::parse($gen->compact());
        $standard = HybridIdGenerator::parse($gen->standard());
        $extended = HybridIdGenerator::parse($gen->extended());

        $this->assertSame('compact', $compact['profile']);
        $this->assertNull($compact['node']); // compact has no node
        $this->assertSame(8, strlen($compact['random']));

        $this->assertSame('standard', $standard['profile']);
        $this->assertSame(10, strlen($standard['random']));

        $this->assertSame('extended', $extended['profile']);
        $this->assertSame(14, strlen($extended['random']));
    }

    public function testParseInvalidIdReturnsPartialData(): void
    {
        $result = HybridIdGenerator::parse('usr_invalidbody');

        $this->assertFalse($result['valid']);
        $this->assertSame('usr', $result['prefix']);
        $this->assertSame('invalidbody', $result['body']);
        $this->assertArrayNotHasKey('profile', $result);
        $this->assertArrayNotHasKey('timestamp', $result);
    }

    public function testParseEmptyStringReturnsInvalid(): void
    {
        $result = HybridIdGenerator::parse('');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['prefix']);
        $this->assertNull($result['body']);
    }

    public function testParseSpecialCharsReturnsInvalid(): void
    {
        $result = HybridIdGenerator::parse('abc!@#');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['prefix']);
        $this->assertNull($result['body']);
    }

    public function testParseTimestampMatchesExtract(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('txn');

        $parsed = HybridIdGenerator::parse($id);
        $extracted = HybridIdGenerator::extractTimestamp($id);

        $this->assertSame($extracted, $parsed['timestamp']);
    }

    public function testParseNodeMatchesExtract(): void
    {
        $gen = new HybridIdGenerator(node: 'Z9');
        $id = $gen->generate();

        $parsed = HybridIdGenerator::parse($id);

        $this->assertSame('Z9', $parsed['node']);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testDetectProfileRejectsNullBytes(): void
    {
        $this->assertNull(HybridIdGenerator::detectProfile(str_repeat("\0", 20)));
    }

    public function testDetectProfileRejectsUnicodeOfValidLength(): void
    {
        $this->assertNull(HybridIdGenerator::detectProfile('ABCDEFGHIJKLMNOPQRñ'));
    }

    public function testIsValidRejectsMaxLengthJunk(): void
    {
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('-', 16)));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat(' ', 20)));
        $this->assertFalse(HybridIdGenerator::isValid(str_repeat('+', 24)));
    }

    // -------------------------------------------------------------------------
    // Overflow guard
    // -------------------------------------------------------------------------

    public function testEncodeBase62ThrowsOnOverflow(): void
    {
        $this->expectException(IdOverflowException::class);

        HybridIdGenerator::encodeBase62(3844, 2);
    }

    public function testEncodeBase62DoesNotThrowWithinBounds(): void
    {
        $result = HybridIdGenerator::encodeBase62(3843, 2);
        $this->assertSame(2, strlen($result));

        $result = HybridIdGenerator::encodeBase62(0, 8);
        $this->assertSame('00000000', $result);
    }

    public function testDecodeBase62RoundTrip(): void
    {
        $values = [0, 1, 61, 62, 3843, 100000, PHP_INT_MAX >> 16];

        foreach ($values as $value) {
            $encoded = HybridIdGenerator::encodeBase62($value, 12);
            $decoded = HybridIdGenerator::decodeBase62($encoded);

            $this->assertSame($value, $decoded, "Round-trip failed for value {$value}");
        }
    }

    public function testDecodeBase62ThrowsOnInvalidChar(): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Invalid base62 character');

        HybridIdGenerator::decodeBase62('abc!def');
    }

    public function testDecodeBase62ThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidIdException::class);
        $this->expectExceptionMessage('Cannot decode empty string');

        HybridIdGenerator::decodeBase62('');
    }

    public function testDecodeBase62ThrowsOnOverflow(): void
    {
        $this->expectException(IdOverflowException::class);
        $this->expectExceptionMessage('exceeds 64-bit');

        // 11 base62 chars max value = 62^11 - 1 > PHP_INT_MAX
        HybridIdGenerator::decodeBase62('zzzzzzzzzzz');
    }

    public function testDecodeBase62HandlesLeadingZeros(): void
    {
        $this->assertSame(0, HybridIdGenerator::decodeBase62('0'));
        $this->assertSame(0, HybridIdGenerator::decodeBase62('0000000000'));
        $this->assertSame(0, HybridIdGenerator::decodeBase62('000000000000000'));
        $this->assertSame(1, HybridIdGenerator::decodeBase62('00001'));
        $this->assertSame(62, HybridIdGenerator::decodeBase62('00010'));
    }

    public function testDecodeBase62ThrowsOnExcessiveLength(): void
    {
        $this->expectException(IdOverflowException::class);
        $this->expectExceptionMessage('exceeds 64-bit');

        // 12+ chars always overflows — caught by early length guard
        HybridIdGenerator::decodeBase62('zzzzzzzzzzzz');
    }

    public function testDecodeBase62HandlesMaxSafeValue(): void
    {
        // Encode PHP_INT_MAX and verify round-trip
        $encoded = HybridIdGenerator::encodeBase62(PHP_INT_MAX, 11);
        $decoded = HybridIdGenerator::decodeBase62($encoded);

        $this->assertSame(PHP_INT_MAX, $decoded);
    }

    public function testDecodeBase62ThrowsOnValueJustAboveIntMax(): void
    {
        $this->expectException(IdOverflowException::class);

        // Encode PHP_INT_MAX, then increment last char to force overflow
        $encoded = HybridIdGenerator::encodeBase62(PHP_INT_MAX, 11);
        $lastChar = $encoded[10];
        $nextCharPos = (HybridIdGenerator::decodeBase62($lastChar)) + 1;

        // If last char is 'z' (61), we can't increment — use a known overflow string instead
        if ($nextCharPos > 61) {
            // AzL8n0Y58m8 is PHP_INT_MAX in base62; the next representable value overflows
            HybridIdGenerator::decodeBase62('AzL8n0Y58m9');
        } else {
            $overflowStr = substr($encoded, 0, 10) . '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'[$nextCharPos];
            HybridIdGenerator::decodeBase62($overflowStr);
        }
    }

    public function testEncodeBase62ThrowsOnNegative(): void
    {
        $this->expectException(IdOverflowException::class);
        $this->expectExceptionMessage('negative');

        HybridIdGenerator::encodeBase62(-1, 8);
    }

    // -------------------------------------------------------------------------
    // Batch generation (#118)
    // -------------------------------------------------------------------------

    public function testGenerateBatchReturnsCorrectCount(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $ids = $gen->generateBatch(10);

        $this->assertCount(10, $ids);
    }

    public function testGenerateBatchReturnsUniqueIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $ids = $gen->generateBatch(100);

        $this->assertCount(100, array_unique($ids));
    }

    public function testGenerateBatchIsMonotonicallyOrdered(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $ids = $gen->generateBatch(50);

        for ($i = 1; $i < count($ids); $i++) {
            $this->assertSame(
                -1,
                HybridIdGenerator::compare($ids[$i - 1], $ids[$i]),
                "ID at index {$i} should be greater than ID at index " . ($i - 1),
            );
        }
    }

    public function testGenerateBatchAppliesPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $ids = $gen->generateBatch(5, 'usr');

        foreach ($ids as $id) {
            $this->assertStringStartsWith('usr_', $id);
            $this->assertTrue(HybridIdGenerator::isValid($id));
        }
    }

    public function testGenerateBatchRejectsZeroCount(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);

        $gen->generateBatch(0);
    }

    public function testGenerateBatchRejectsNegativeCount(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);

        $gen->generateBatch(-1);
    }

    public function testGenerateBatchRejectsExcessiveCount(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->expectException(\InvalidArgumentException::class);

        $gen->generateBatch(100_001);
    }

    public function testGenerateBatchSingleIdWorks(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $ids = $gen->generateBatch(1);

        $this->assertCount(1, $ids);
        $this->assertTrue(HybridIdGenerator::isValid($ids[0]));
    }

    public function testGenerateBatchAllProfilesWork(): void
    {
        $compact = new HybridIdGenerator(profile: 'compact');
        $standard = new HybridIdGenerator(profile: 'standard', node: 'T1');
        $extended = new HybridIdGenerator(profile: 'extended', node: 'T1');

        $this->assertCount(5, $compact->generateBatch(5));
        $this->assertCount(5, $standard->generateBatch(5));
        $this->assertCount(5, $extended->generateBatch(5));
    }

    // -------------------------------------------------------------------------
    // Temporal range helpers (#102)
    // -------------------------------------------------------------------------

    public function testMinForTimestampReturnsCorrectLength(): void
    {
        $ts = (int) (microtime(true) * 1000);

        $this->assertSame(16, strlen(HybridIdGenerator::minForTimestamp($ts, 'compact')));
        $this->assertSame(20, strlen(HybridIdGenerator::minForTimestamp($ts, 'standard')));
        $this->assertSame(24, strlen(HybridIdGenerator::minForTimestamp($ts, 'extended')));
    }

    public function testMaxForTimestampReturnsCorrectLength(): void
    {
        $ts = (int) (microtime(true) * 1000);

        $this->assertSame(16, strlen(HybridIdGenerator::maxForTimestamp($ts, 'compact')));
        $this->assertSame(20, strlen(HybridIdGenerator::maxForTimestamp($ts, 'standard')));
        $this->assertSame(24, strlen(HybridIdGenerator::maxForTimestamp($ts, 'extended')));
    }

    public function testMinForTimestampIsLowerBound(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();
        $ts = HybridIdGenerator::extractTimestamp($id);

        $min = HybridIdGenerator::minForTimestamp($ts);

        $this->assertLessThanOrEqual(0, strcmp($min, HybridIdGenerator::extractPrefix($id) !== null ? substr($id, strpos($id, '_') + 1) : $id));
    }

    public function testMaxForTimestampIsUpperBound(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate();
        $ts = HybridIdGenerator::extractTimestamp($id);
        $body = $id; // unprefixed

        $max = HybridIdGenerator::maxForTimestamp($ts);

        $this->assertGreaterThanOrEqual(0, strcmp($max, $body));
    }

    public function testRangeHelpersBracketGeneratedIds(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $before = (int) (microtime(true) * 1000);
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $gen->generate();
        }
        $after = (int) (microtime(true) * 1000) + 100; // generous upper bound

        $min = HybridIdGenerator::minForTimestamp($before);
        $max = HybridIdGenerator::maxForTimestamp($after);

        foreach ($ids as $id) {
            $this->assertGreaterThanOrEqual(0, strcmp($id, $min), "ID should be >= min boundary");
            $this->assertLessThanOrEqual(0, strcmp($id, $max), "ID should be <= max boundary");
        }
    }

    public function testMinForTimestampEndsWithZeros(): void
    {
        $ts = (int) (microtime(true) * 1000);
        $min = HybridIdGenerator::minForTimestamp($ts, 'standard');

        // Node + random portion (chars 8-19) should all be '0'
        $this->assertSame(str_repeat('0', 12), substr($min, 8));
    }

    public function testMaxForTimestampEndsWithZ(): void
    {
        $ts = (int) (microtime(true) * 1000);
        $max = HybridIdGenerator::maxForTimestamp($ts, 'standard');

        // Node + random portion (chars 8-19) should all be 'z'
        $this->assertSame(str_repeat('z', 12), substr($max, 8));
    }

    public function testRangeHelpersRejectInvalidProfile(): void
    {
        $ts = (int) (microtime(true) * 1000);

        $this->expectException(\InvalidArgumentException::class);
        HybridIdGenerator::minForTimestamp($ts, 'nonexistent');
    }

    // -------------------------------------------------------------------------
    // Production node guard (#103)
    // -------------------------------------------------------------------------

    public function testRequireExplicitNodeThrowsWhenNodeMissing(): void
    {
        $this->expectException(NodeRequiredException::class);
        $this->expectExceptionMessage('Explicit node is required');

        new HybridIdGenerator(requireExplicitNode: true);
    }

    public function testRequireExplicitNodeAcceptsExplicitNode(): void
    {
        $gen = new HybridIdGenerator(node: 'A1', requireExplicitNode: true);

        $this->assertSame('A1', $gen->getNode());
    }

    public function testRequireExplicitNodeDefaultIsTrue(): void
    {
        // Default requireExplicitNode is true — standard profile throws without node
        $this->expectException(NodeRequiredException::class);

        new HybridIdGenerator();
    }

    public function testRequireExplicitNodeCompactDoesNotThrow(): void
    {
        // Compact profile has no node field — should not throw even with requireExplicitNode=true
        $gen = new HybridIdGenerator(profile: Profile::Compact);

        $this->assertSame(16, strlen($gen->generate()));
    }

    public function testRequireExplicitNodeFalseAllowsAutoDetect(): void
    {
        // Explicitly disabling requireExplicitNode allows auto-detection
        $gen = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertSame(2, strlen($gen->getNode()));
    }

    public function testAutoDetectNodeIsNonDeterministic(): void
    {
        // Two instances should (almost certainly) get different auto-detected nodes
        $gen1 = new HybridIdGenerator(requireExplicitNode: false);
        $gen2 = new HybridIdGenerator(requireExplicitNode: false);

        // With 3844 possible values, P(collision) ≈ 0.026% — run multiple times
        $nodes = [];
        for ($i = 0; $i < 10; $i++) {
            $gen = new HybridIdGenerator(requireExplicitNode: false);
            $nodes[] = $gen->getNode();
        }

        // At least 2 distinct values out of 10 (P(all same) ≈ (1/3844)^9 ≈ 0)
        $this->assertGreaterThan(1, count(array_unique($nodes)));
    }

    public function testExtractNodeReturnsNullForCompact(): void
    {
        $gen = new HybridIdGenerator(profile: Profile::Compact);
        $id = $gen->generate();

        $this->assertNull(HybridIdGenerator::extractNode($id));
    }

    public function testFromEnvReadsRequireNode(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=');
            putenv('HYBRID_ID_NODE=');
            putenv('HYBRID_ID_REQUIRE_NODE=1');

            $this->expectException(NodeRequiredException::class);
            $this->expectExceptionMessage('Explicit node is required');

            HybridIdGenerator::fromEnv();
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');
        }
    }

    public function testFromEnvRequireNodeWithExplicitNode(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=');
            putenv('HYBRID_ID_NODE=Z9');
            putenv('HYBRID_ID_REQUIRE_NODE=1');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame('Z9', $gen->getNode());
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');
        }
    }

    public function testFromEnvRequireNodeZeroIsDisabled(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=');
            putenv('HYBRID_ID_NODE=');
            putenv('HYBRID_ID_REQUIRE_NODE=0');

            // Should not throw — '0' means disabled
            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame(2, strlen($gen->getNode()));
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
            putenv('HYBRID_ID_REQUIRE_NODE');
        }
    }
}
