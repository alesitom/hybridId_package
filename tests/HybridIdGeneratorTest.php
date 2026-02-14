<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;
use PHPUnit\Framework\TestCase;

final class HybridIdGeneratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface
    // -------------------------------------------------------------------------

    public function testImplementsIdGenerator(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertInstanceOf(IdGenerator::class, $gen);
    }

    // -------------------------------------------------------------------------
    // Generation
    // -------------------------------------------------------------------------

    public function testGenerateReturnsDefaultProfileLength(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame(20, strlen($gen->generate()));
    }

    public function testCompactReturns16Chars(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame(16, strlen($gen->compact()));
    }

    public function testStandardReturns20Chars(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame(20, strlen($gen->standard()));
    }

    public function testExtendedReturns24Chars(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame(24, strlen($gen->extended()));
    }

    public function testGenerateUsesConfiguredProfile(): void
    {
        $gen = new HybridIdGenerator(profile: 'compact');

        $this->assertSame(16, strlen($gen->generate()));
    }

    public function testGenerateContainsOnlyBase62Characters(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{20}$/', $gen->generate());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{16}$/', $gen->compact());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $gen->extended());
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $gen = new HybridIdGenerator();
        $ids = [];

        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $gen->generate();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    public function testCompactProducesUniqueIds(): void
    {
        $gen = new HybridIdGenerator();
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
        $standard = new HybridIdGenerator(profile: 'standard');
        $extended = new HybridIdGenerator(profile: 'extended');

        $this->assertSame('compact', $compact->getProfile());
        $this->assertSame('standard', $standard->getProfile());
        $this->assertSame('extended', $extended->getProfile());
    }

    public function testConstructorRejectsInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile');

        new HybridIdGenerator(profile: 'ultra');
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
        $gen = new HybridIdGenerator();

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
        $this->assertSame('A1', HybridIdGenerator::extractNode($id1));
        $this->assertSame('B2', HybridIdGenerator::extractNode($id2));
    }

    public function testIndependentMonotonicGuards(): void
    {
        $gen1 = new HybridIdGenerator();
        $gen2 = new HybridIdGenerator();

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

    // -------------------------------------------------------------------------
    // Validation (static)
    // -------------------------------------------------------------------------

    public function testIsValidAcceptsAllProfiles(): void
    {
        $gen = new HybridIdGenerator();

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
        $gen = new HybridIdGenerator();
        $id = $gen->generate();
        $after = (int) (microtime(true) * 1000);

        $timestamp = HybridIdGenerator::extractTimestamp($id);

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testExtractTimestampWorksForAllProfiles(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator();

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
        $gen = new HybridIdGenerator();
        $id = $gen->generate();
        $dt = HybridIdGenerator::extractDateTime($id);
        $now = new \DateTimeImmutable();

        $diff = abs($now->getTimestamp() - $dt->getTimestamp());

        $this->assertLessThan(2, $diff);
    }

    public function testExtractNodeReturnsConfiguredNode(): void
    {
        $gen = new HybridIdGenerator(node: 'N1');

        $this->assertSame('N1', HybridIdGenerator::extractNode($gen->compact()));
        $this->assertSame('N1', HybridIdGenerator::extractNode($gen->standard()));
        $this->assertSame('N1', HybridIdGenerator::extractNode($gen->extended()));
    }

    public function testExtractNodeAutoDetectsConsistently(): void
    {
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();

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
        $this->assertSame(35.7, HybridIdGenerator::entropy('compact'));
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
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();
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
        $this->assertSame(2, $config['node']);
        $this->assertSame(6, $config['random']);
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
            putenv('HYBRID_ID_NODE');

            $gen = HybridIdGenerator::fromEnv();

            $this->assertSame('standard', $gen->getProfile());
            $this->assertSame(20, strlen($gen->generate()));
        } finally {
            putenv('HYBRID_ID_PROFILE');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testFromEnvRejectsInvalidProfile(): void
    {
        try {
            putenv('HYBRID_ID_PROFILE=../../etc/passwd');
            putenv('HYBRID_ID_NODE');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid profile');

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
            $this->expectExceptionMessage('Node must be exactly 2');

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
            $this->expectExceptionMessage('Node must be exactly 2');

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
        $gen = new HybridIdGenerator();
        $id = $gen->generate('usr');

        $this->assertStringStartsWith('usr_', $id);
        $this->assertSame(24, strlen($id)); // 3 prefix + 1 underscore + 20 standard
    }

    public function testAllProfilesWithPrefix(): void
    {
        $gen = new HybridIdGenerator();

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
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $this->assertSame(20, strlen($id));
        $this->assertStringNotContainsString('_', $id);
    }

    public function testGenerateWithNullPrefixIsUnchanged(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate(null);

        $this->assertSame(20, strlen($id));
        $this->assertStringNotContainsString('_', $id);
    }

    public function testPrefixedIdIsValid(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertTrue(HybridIdGenerator::isValid($gen->generate('usr')));
        $this->assertTrue(HybridIdGenerator::isValid($gen->compact('log')));
        $this->assertTrue(HybridIdGenerator::isValid($gen->extended('txn')));
    }

    public function testDetectProfileWithPrefix(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame('standard', HybridIdGenerator::detectProfile($gen->generate('usr')));
        $this->assertSame('compact', HybridIdGenerator::detectProfile($gen->compact('log')));
        $this->assertSame('extended', HybridIdGenerator::detectProfile($gen->extended('txn')));
    }

    public function testExtractTimestampWithPrefix(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();
        $id = $gen->generate('usr');
        $dt = HybridIdGenerator::extractDateTime($id);
        $now = new \DateTimeImmutable();

        $diff = abs($now->getTimestamp() - $dt->getTimestamp());

        $this->assertLessThan(2, $diff);
    }

    public function testExtractPrefix(): void
    {
        $gen = new HybridIdGenerator();

        $this->assertSame('usr', HybridIdGenerator::extractPrefix($gen->generate('usr')));
        $this->assertSame('log', HybridIdGenerator::extractPrefix($gen->compact('log')));
        $this->assertNull(HybridIdGenerator::extractPrefix($gen->generate()));
    }

    public function testPrefixValidationRejectsEmpty(): void
    {
        $gen = new HybridIdGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('');
    }

    public function testPrefixValidationRejectsUppercase(): void
    {
        $gen = new HybridIdGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('USR');
    }

    public function testPrefixValidationRejectsSpecialChars(): void
    {
        $gen = new HybridIdGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('usr!');
    }

    public function testPrefixValidationRejectsTooLong(): void
    {
        $gen = new HybridIdGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('abcdefghi'); // 9 chars, max is 8
    }

    public function testPrefixValidationRejectsStartingWithDigit(): void
    {
        $gen = new HybridIdGenerator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prefix must be');

        $gen->generate('1usr');
    }

    public function testPrefixMaxLengthIsAccepted(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate('abcdefgh'); // exactly 8 chars

        $this->assertStringStartsWith('abcdefgh_', $id);
        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    public function testPrefixWithDigitsAfterFirstChar(): void
    {
        $gen = new HybridIdGenerator();
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
    }

    public function testPrefixedIdsAreUnique(): void
    {
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();
        $id1 = $gen->generate();
        usleep(2000);
        $id2 = $gen->generate();

        $this->assertSame(-1, HybridIdGenerator::compare($id1, $id2));
        $this->assertSame(1, HybridIdGenerator::compare($id2, $id1));
    }

    public function testCompareWorksWithUsort(): void
    {
        $gen = new HybridIdGenerator();
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
        $gen = new HybridIdGenerator();
        $id1 = $gen->generate('usr');
        usleep(2000);
        $id2 = $gen->generate('ord');

        $this->assertSame(-1, HybridIdGenerator::compare($id1, $id2));
    }

    public function testCompareEqualTimestampsReturnsZero(): void
    {
        $gen = new HybridIdGenerator();
        $id = $gen->generate();

        $this->assertSame(0, HybridIdGenerator::compare($id, $id));
    }

    // -------------------------------------------------------------------------
    // Custom profiles
    // -------------------------------------------------------------------------

    public function testRegisterProfileAndGenerate(): void
    {
        try {
            HybridIdGenerator::registerProfile('ultra', 22);

            $gen = new HybridIdGenerator(profile: 'ultra');
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
            HybridIdGenerator::registerProfile('tiny', 2);

            $config = HybridIdGenerator::profileConfig('tiny');

            $this->assertSame(12, $config['length']);
            $this->assertSame(8, $config['ts']);
            $this->assertSame(2, $config['node']);
            $this->assertSame(2, $config['random']);
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

            $gen = new HybridIdGenerator(profile: 'ultra');
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
            $this->expectExceptionMessage('at least 1');

            HybridIdGenerator::registerProfile('norandom', 0);
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
        $this->expectException(\OverflowException::class);

        $method = new \ReflectionMethod(HybridIdGenerator::class, 'encodeBase62');
        $method->invoke(null, 3844, 2);
    }

    public function testEncodeBase62DoesNotThrowWithinBounds(): void
    {
        $method = new \ReflectionMethod(HybridIdGenerator::class, 'encodeBase62');

        $result = $method->invoke(null, 3843, 2);
        $this->assertSame(2, strlen($result));

        $result = $method->invoke(null, 0, 8);
        $this->assertSame('00000000', $result);
    }
}
