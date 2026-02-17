<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\HybridIdGenerator;
use HybridId\Profile;
use PHPUnit\Framework\TestCase;

final class BlindModeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Basic generation
    // -------------------------------------------------------------------------

    public function testBlindGeneratesValidId(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate();

        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    public function testBlindCompactProfile(): void
    {
        $gen = new HybridIdGenerator(profile: Profile::Compact, blind: true);
        $id = $gen->generate();

        $this->assertSame(16, strlen($id));
        $this->assertTrue(HybridIdGenerator::isValid($id));
        $this->assertSame('compact', HybridIdGenerator::detectProfile($id));
    }

    public function testBlindStandardProfile(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate();

        $this->assertSame(20, strlen($id));
        $this->assertTrue(HybridIdGenerator::isValid($id));
        $this->assertSame('standard', HybridIdGenerator::detectProfile($id));
    }

    public function testBlindExtendedProfile(): void
    {
        $gen = new HybridIdGenerator(profile: Profile::Extended, blind: true);
        $id = $gen->generate();

        $this->assertSame(24, strlen($id));
        $this->assertTrue(HybridIdGenerator::isValid($id));
        $this->assertSame('extended', HybridIdGenerator::detectProfile($id));
    }

    public function testBlindWithPrefix(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate('usr');

        $this->assertStringStartsWith('usr_', $id);
        $this->assertTrue(HybridIdGenerator::isValid($id));
        $this->assertSame('usr', HybridIdGenerator::extractPrefix($id));
    }

    // -------------------------------------------------------------------------
    // Timestamp opacity
    // -------------------------------------------------------------------------

    public function testBlindTimestampIsNotRealTime(): void
    {
        $before = (int) (microtime(true) * 1000);
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate();
        $after = (int) (microtime(true) * 1000);

        $extracted = HybridIdGenerator::extractTimestamp($id);

        // The HMAC-derived "timestamp" should almost certainly NOT fall
        // within the actual time window (probability ~0 given HMAC output range)
        $this->assertTrue(
            $extracted < $before || $extracted > $after + 1000,
            'Blind ID timestamp should not match real wall-clock time',
        );
    }

    // -------------------------------------------------------------------------
    // Instance isolation
    // -------------------------------------------------------------------------

    public function testBlindTwoInstancesProduceDifferentPrefixes(): void
    {
        $gen1 = new HybridIdGenerator(profile: Profile::Compact, blind: true);
        $gen2 = new HybridIdGenerator(profile: Profile::Compact, blind: true);

        $id1 = $gen1->generate();
        $id2 = $gen2->generate();

        // Different secrets → different HMAC prefixes (first 8 chars)
        $this->assertNotSame(substr($id1, 0, 8), substr($id2, 0, 8));
    }

    // -------------------------------------------------------------------------
    // Node handling
    // -------------------------------------------------------------------------

    public function testBlindBypassesRequireExplicitNode(): void
    {
        // Standard profile with requireExplicitNode=true (default) would throw
        // without a node, but blind: true bypasses this
        $gen = new HybridIdGenerator(blind: true);

        $this->assertSame(20, strlen($gen->generate()));
    }

    public function testBlindWithExplicitNodeWorks(): void
    {
        $gen = new HybridIdGenerator(node: 'A1', blind: true);
        $id = $gen->generate();

        $this->assertTrue(HybridIdGenerator::isValid($id));
        $this->assertSame(20, strlen($id));
    }

    // -------------------------------------------------------------------------
    // Monotonic guard
    // -------------------------------------------------------------------------

    public function testBlindMonotonicGuardStillWorks(): void
    {
        $gen = new HybridIdGenerator(profile: Profile::Compact, blind: true);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $gen->generate();
        }

        // All IDs should be unique (monotonic timestamps → unique HMAC inputs)
        $this->assertCount(100, array_unique($ids));
    }

    // -------------------------------------------------------------------------
    // Batch generation
    // -------------------------------------------------------------------------

    public function testBlindGenerateBatch(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $batch = $gen->generateBatch(10);

        $this->assertCount(10, $batch);
        $this->assertCount(10, array_unique($batch));

        foreach ($batch as $id) {
            $this->assertTrue(HybridIdGenerator::isValid($id));
        }
    }

    // -------------------------------------------------------------------------
    // Getter
    // -------------------------------------------------------------------------

    public function testIsBlindGetter(): void
    {
        $blind = new HybridIdGenerator(blind: true);
        $normal = new HybridIdGenerator(requireExplicitNode: false);

        $this->assertTrue($blind->isBlind());
        $this->assertFalse($normal->isBlind());
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testBlindIdPassesInstanceValidation(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate();

        $this->assertTrue($gen->validate($id));
    }

    public function testBlindIdPassesPrefixValidation(): void
    {
        $gen = new HybridIdGenerator(blind: true);
        $id = $gen->generate('usr');

        $this->assertTrue($gen->validate($id, 'usr'));
        $this->assertFalse($gen->validate($id, 'ord'));
    }

    // -------------------------------------------------------------------------
    // fromEnv
    // -------------------------------------------------------------------------

    public function testBlindFromEnv(): void
    {
        putenv('HYBRID_ID_BLIND=1');
        putenv('HYBRID_ID_NODE=A1');

        try {
            $gen = HybridIdGenerator::fromEnv();
            $this->assertTrue($gen->isBlind());
        } finally {
            putenv('HYBRID_ID_BLIND');
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testBlindFromEnvDisabledByDefault(): void
    {
        putenv('HYBRID_ID_NODE=A1');

        try {
            $gen = HybridIdGenerator::fromEnv();
            $this->assertFalse($gen->isBlind());
        } finally {
            putenv('HYBRID_ID_NODE');
        }
    }

    public function testBlindFromEnvZeroMeansDisabled(): void
    {
        putenv('HYBRID_ID_BLIND=0');
        putenv('HYBRID_ID_NODE=A1');

        try {
            $gen = HybridIdGenerator::fromEnv();
            $this->assertFalse($gen->isBlind());
        } finally {
            putenv('HYBRID_ID_BLIND');
            putenv('HYBRID_ID_NODE');
        }
    }

    // -------------------------------------------------------------------------
    // CLI --blind flag
    // -------------------------------------------------------------------------

    public function testCliBlindFlag(): void
    {
        $output = new \HybridId\Cli\BufferedOutput();
        $app = new \HybridId\Cli\Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--blind']);

        $this->assertSame(0, $exitCode);
        $id = $output->getLines()[0];
        $this->assertTrue(HybridIdGenerator::isValid($id));
    }

    public function testCliBlindWithCompactProfile(): void
    {
        $output = new \HybridId\Cli\BufferedOutput();
        $app = new \HybridId\Cli\Application($output);

        $exitCode = $app->run(['hybrid-id', 'generate', '--blind', '-p', 'compact']);

        $this->assertSame(0, $exitCode);
        $id = $output->getLines()[0];
        $this->assertSame(16, strlen($id));
    }
}
