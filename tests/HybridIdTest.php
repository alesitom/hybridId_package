<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\HybridId;
use PHPUnit\Framework\TestCase;

final class HybridIdTest extends TestCase
{
    protected function setUp(): void
    {
        HybridId::reset();
    }

    // -------------------------------------------------------------------------
    // Generation
    // -------------------------------------------------------------------------

    public function testGenerateReturnsDefaultProfileLength(): void
    {
        $id = HybridId::generate();

        $this->assertSame(20, strlen($id));
    }

    public function testCompactReturns16Chars(): void
    {
        $this->assertSame(16, strlen(HybridId::compact()));
    }

    public function testStandardReturns20Chars(): void
    {
        $this->assertSame(20, strlen(HybridId::standard()));
    }

    public function testExtendedReturns24Chars(): void
    {
        $this->assertSame(24, strlen(HybridId::extended()));
    }

    public function testGenerateContainsOnlyBase62Characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{20}$/', HybridId::generate());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{16}$/', HybridId::compact());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', HybridId::extended());
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = HybridId::generate();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    public function testCompactProducesUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = HybridId::compact();
        }

        $this->assertCount(1000, array_unique($ids));
    }

    // -------------------------------------------------------------------------
    // Configure
    // -------------------------------------------------------------------------

    public function testConfigureChangesDefaultProfile(): void
    {
        HybridId::configure(['profile' => 'compact']);
        $id = HybridId::generate();

        $this->assertSame(16, strlen($id));
    }

    public function testConfigureSetsNode(): void
    {
        HybridId::configure(['node' => 'X9']);
        $id = HybridId::generate();

        $this->assertSame('X9', HybridId::extractNode($id));
    }

    public function testConfigureRejectsInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile');

        HybridId::configure(['profile' => 'ultra']);
    }

    public function testConfigureRejectsInvalidNodeLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        HybridId::configure(['node' => 'ABC']);
    }

    public function testConfigureRejectsNonBase62Node(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridId::configure(['node' => '!@']);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testIsValidAcceptsAllProfiles(): void
    {
        $this->assertTrue(HybridId::isValid(HybridId::compact()));
        $this->assertTrue(HybridId::isValid(HybridId::standard()));
        $this->assertTrue(HybridId::isValid(HybridId::extended()));
    }

    public function testIsValidRejectsWrongLengths(): void
    {
        $this->assertFalse(HybridId::isValid(''));
        $this->assertFalse(HybridId::isValid('abc'));
        $this->assertFalse(HybridId::isValid(str_repeat('A', 15)));
        $this->assertFalse(HybridId::isValid(str_repeat('A', 17)));
        $this->assertFalse(HybridId::isValid(str_repeat('A', 19)));
        $this->assertFalse(HybridId::isValid(str_repeat('A', 21)));
    }

    public function testIsValidRejectsNonBase62Characters(): void
    {
        $this->assertFalse(HybridId::isValid('ABCDEFGH!@#$%^&*'));
        $this->assertFalse(HybridId::isValid('ABCDEFGH12345678-_+='));
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    public function testExtractTimestampReturnsReasonableValue(): void
    {
        $before = (int) (microtime(true) * 1000);
        $id = HybridId::generate();
        $after = (int) (microtime(true) * 1000);

        $timestamp = HybridId::extractTimestamp($id);

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testExtractTimestampWorksForAllProfiles(): void
    {
        $before = (int) (microtime(true) * 1000);

        $tsCompact = HybridId::extractTimestamp(HybridId::compact());
        $tsStandard = HybridId::extractTimestamp(HybridId::standard());
        $tsExtended = HybridId::extractTimestamp(HybridId::extended());

        // Monotonic guard may increment beyond real time, allow small drift
        $this->assertGreaterThanOrEqual($before, $tsCompact);
        $this->assertLessThan($tsStandard, $tsCompact);
        $this->assertLessThan($tsExtended, $tsStandard);
    }

    public function testExtractTimestampThrowsOnInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridId::extractTimestamp('invalid');
    }

    public function testExtractDateTimeReturnsCurrentTime(): void
    {
        $id = HybridId::generate();
        $dt = HybridId::extractDateTime($id);
        $now = new \DateTimeImmutable();

        $diff = abs($now->getTimestamp() - $dt->getTimestamp());

        $this->assertLessThan(2, $diff);
    }

    public function testExtractNodeReturnsConfiguredNode(): void
    {
        HybridId::configure(['node' => 'N1']);

        $this->assertSame('N1', HybridId::extractNode(HybridId::compact()));
        $this->assertSame('N1', HybridId::extractNode(HybridId::standard()));
        $this->assertSame('N1', HybridId::extractNode(HybridId::extended()));
    }

    public function testExtractNodeAutoDetectsConsistently(): void
    {
        $id1 = HybridId::generate();
        $id2 = HybridId::generate();

        $this->assertSame(
            HybridId::extractNode($id1),
            HybridId::extractNode($id2),
        );
    }

    // -------------------------------------------------------------------------
    // Profile detection
    // -------------------------------------------------------------------------

    public function testDetectProfileIdentifiesCorrectly(): void
    {
        $this->assertSame('compact', HybridId::detectProfile(HybridId::compact()));
        $this->assertSame('standard', HybridId::detectProfile(HybridId::standard()));
        $this->assertSame('extended', HybridId::detectProfile(HybridId::extended()));
    }

    public function testDetectProfileReturnsNullForInvalid(): void
    {
        $this->assertNull(HybridId::detectProfile('invalid'));
        $this->assertNull(HybridId::detectProfile(''));
    }

    // -------------------------------------------------------------------------
    // Entropy
    // -------------------------------------------------------------------------

    public function testEntropyReturnsCorrectBits(): void
    {
        $this->assertSame(35.7, HybridId::entropy('compact'));
        $this->assertSame(59.5, HybridId::entropy('standard'));
        $this->assertSame(83.4, HybridId::entropy('extended'));
    }

    public function testEntropyUsesDefaultProfile(): void
    {
        HybridId::configure(['profile' => 'extended']);

        $this->assertSame(83.4, HybridId::entropy());
    }

    public function testEntropyThrowsOnInvalidProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridId::entropy('nonexistent');
    }

    // -------------------------------------------------------------------------
    // Chronological ordering
    // -------------------------------------------------------------------------

    public function testIdsAreChronologicallyOrdered(): void
    {
        $id1 = HybridId::generate();
        usleep(2000); // 2ms
        $id2 = HybridId::generate();

        $this->assertLessThanOrEqual(
            HybridId::extractTimestamp($id2),
            HybridId::extractTimestamp($id1),
        );
    }

    // -------------------------------------------------------------------------
    // Monotonic guard
    // -------------------------------------------------------------------------

    public function testTimestampNeverDecreases(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 100; $i++) {
            $timestamps[] = HybridId::extractTimestamp(HybridId::generate());
        }

        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThanOrEqual($timestamps[$i - 1], $timestamps[$i]);
        }
    }

    public function testTimestampStrictlyIncrementsWithinSameMillisecond(): void
    {
        // Generate many IDs rapidly — they will likely share the same real ms
        $timestamps = [];
        for ($i = 0; $i < 50; $i++) {
            $timestamps[] = HybridId::extractTimestamp(HybridId::generate());
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
    // Reset
    // -------------------------------------------------------------------------

    public function testResetRestoresDefaults(): void
    {
        HybridId::configure(['profile' => 'compact', 'node' => 'ZZ']);
        HybridId::reset();

        $id = HybridId::generate();

        $this->assertSame(20, strlen($id));
        $this->assertNotSame('ZZ', HybridId::extractNode($id));
    }

    // -------------------------------------------------------------------------
    // Profile info
    // -------------------------------------------------------------------------

    public function testProfilesReturnsAllNames(): void
    {
        $this->assertSame(['compact', 'standard', 'extended'], HybridId::profiles());
    }

    public function testProfileConfigReturnsCorrectData(): void
    {
        $config = HybridId::profileConfig('compact');

        $this->assertSame(16, $config['length']);
        $this->assertSame(8, $config['ts']);
        $this->assertSame(2, $config['node']);
        $this->assertSame(6, $config['random']);
    }

    public function testProfileConfigThrowsOnInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HybridId::profileConfig('nonexistent');
    }

    // -------------------------------------------------------------------------
    // configureFromEnv
    // -------------------------------------------------------------------------

    public function testConfigureFromEnvReadsProfile(): void
    {
        putenv('HYBRID_ID_PROFILE=compact');
        putenv('HYBRID_ID_NODE=');

        HybridId::configureFromEnv();
        $id = HybridId::generate();

        $this->assertSame(16, strlen($id));

        putenv('HYBRID_ID_PROFILE');
    }

    public function testConfigureFromEnvReadsNode(): void
    {
        putenv('HYBRID_ID_PROFILE=');
        putenv('HYBRID_ID_NODE=Z3');

        HybridId::configureFromEnv();
        $id = HybridId::generate();

        $this->assertSame('Z3', HybridId::extractNode($id));

        putenv('HYBRID_ID_NODE');
    }

    public function testConfigureFromEnvReadsBoth(): void
    {
        putenv('HYBRID_ID_PROFILE=extended');
        putenv('HYBRID_ID_NODE=Q7');

        HybridId::configureFromEnv();
        $id = HybridId::generate();

        $this->assertSame(24, strlen($id));
        $this->assertSame('Q7', HybridId::extractNode($id));

        putenv('HYBRID_ID_PROFILE');
        putenv('HYBRID_ID_NODE');
    }

    public function testConfigureFromEnvIgnoresUnsetVars(): void
    {
        putenv('HYBRID_ID_PROFILE');
        putenv('HYBRID_ID_NODE');

        HybridId::configureFromEnv();
        $id = HybridId::generate();

        // Should remain standard (default)
        $this->assertSame(20, strlen($id));
    }

    public function testConfigureFromEnvRejectsInvalidProfile(): void
    {
        putenv('HYBRID_ID_PROFILE=../../etc/passwd');
        putenv('HYBRID_ID_NODE');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile');

        HybridId::configureFromEnv();

        putenv('HYBRID_ID_PROFILE');
    }

    public function testConfigureFromEnvRejectsInvalidNode(): void
    {
        putenv('HYBRID_ID_PROFILE');
        putenv('HYBRID_ID_NODE=<script>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        HybridId::configureFromEnv();

        putenv('HYBRID_ID_NODE');
    }

    public function testConfigureFromEnvRejectsOversizedNode(): void
    {
        putenv('HYBRID_ID_PROFILE');
        putenv('HYBRID_ID_NODE=' . str_repeat('A', 100));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        HybridId::configureFromEnv();

        putenv('HYBRID_ID_NODE');
    }

    // -------------------------------------------------------------------------
    // Edge cases and boundary tests
    // -------------------------------------------------------------------------

    public function testDetectProfileRejectsNullBytes(): void
    {
        $this->assertNull(HybridId::detectProfile(str_repeat("\0", 20)));
    }

    public function testDetectProfileRejectsUnicodeOfValidLength(): void
    {
        // 20 bytes but contains non-base62 multibyte chars
        $this->assertNull(HybridId::detectProfile('ABCDEFGHIJKLMNOPQRñ'));
    }

    public function testIsValidRejectsMaxLengthJunk(): void
    {
        // Correct length but with non-base62 chars
        $this->assertFalse(HybridId::isValid(str_repeat('-', 16)));
        $this->assertFalse(HybridId::isValid(str_repeat(' ', 20)));
        $this->assertFalse(HybridId::isValid(str_repeat('+', 24)));
    }

    public function testConfigureRejectsEmptyNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        HybridId::configure(['node' => '']);
    }

    public function testConfigureRejectsSingleCharNode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node must be exactly 2');

        HybridId::configure(['node' => 'A']);
    }

    public function testProfileConfigUsesDefaultWhenNullPassed(): void
    {
        HybridId::configure(['profile' => 'extended']);

        $config = HybridId::profileConfig(null);

        $this->assertSame(24, $config['length']);
    }

    public function testEntropyUsesDefaultWhenNullPassed(): void
    {
        HybridId::configure(['profile' => 'compact']);

        $this->assertSame(35.7, HybridId::entropy(null));
    }
}
