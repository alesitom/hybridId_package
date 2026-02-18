<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\HybridIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Performance regression tests for critical paths.
 *
 * These tests use time-based assertions to catch severe regressions.
 * Thresholds are deliberately generous (10-50x headroom) to avoid
 * flaky failures on CI runners with variable load.
 *
 * @since 4.2.0
 */
#[\PHPUnit\Framework\Attributes\Group('benchmark')]
final class PerformanceTest extends TestCase
{
    private HybridIdGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new HybridIdGenerator(node: 'A1');
    }

    // -------------------------------------------------------------------------
    // generate() throughput
    // -------------------------------------------------------------------------

    public function testGenerateSingleIdUnder1ms(): void
    {
        // Warm up — first call may trigger autoloading / JIT compilation
        $this->gen->generate();

        $start = hrtime(true);
        for ($i = 0; $i < 1_000; $i++) {
            $this->gen->generate();
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // 1000 IDs should complete well under 500ms on any modern hardware
        $this->assertLessThan(500, $elapsedMs, sprintf(
            'generate() x1000 took %.1f ms — expected < 500ms',
            $elapsedMs,
        ));
    }

    public function testGenerateWithPrefixUnder1ms(): void
    {
        $this->gen->generate('usr');

        $start = hrtime(true);
        for ($i = 0; $i < 1_000; $i++) {
            $this->gen->generate('usr');
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertLessThan(500, $elapsedMs, sprintf(
            'generate(prefix) x1000 took %.1f ms — expected < 500ms',
            $elapsedMs,
        ));
    }

    // -------------------------------------------------------------------------
    // generateBatch() at various sizes
    // -------------------------------------------------------------------------

    public function testGenerateBatch100Under100ms(): void
    {
        $this->gen->generateBatch(1);

        $start = hrtime(true);
        $batch = $this->gen->generateBatch(100);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount(100, $batch);
        $this->assertLessThan(100, $elapsedMs, sprintf(
            'generateBatch(100) took %.1f ms — expected < 100ms',
            $elapsedMs,
        ));
    }

    public function testGenerateBatch1000Under500ms(): void
    {
        $this->gen->generateBatch(1);

        $start = hrtime(true);
        $batch = $this->gen->generateBatch(1_000);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount(1_000, $batch);
        $this->assertLessThan(500, $elapsedMs, sprintf(
            'generateBatch(1000) took %.1f ms — expected < 500ms',
            $elapsedMs,
        ));
    }

    public function testGenerateBatch10000Under5s(): void
    {
        // Use higher drift cap — 10k IDs at sub-ms speed will drift the
        // monotonic clock well beyond the default 5000ms cap.
        $gen = new HybridIdGenerator(node: 'A1', maxDriftMs: 60_000);
        $gen->generateBatch(1);

        $start = hrtime(true);
        $batch = $gen->generateBatch(10_000);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertCount(10_000, $batch);
        $this->assertLessThan(5_000, $elapsedMs, sprintf(
            'generateBatch(10000) took %.1f ms — expected < 5000ms',
            $elapsedMs,
        ));
    }

    // -------------------------------------------------------------------------
    // encodeBase62 / decodeBase62 round-trip
    // -------------------------------------------------------------------------

    public function testBase62RoundTrip10000Under200ms(): void
    {
        // Warm up
        HybridIdGenerator::encodeBase62(1234567890, 8);
        HybridIdGenerator::decodeBase62('00001ly7VK');

        $maxBase62 = 62 ** 8 - 1; // max value encodable in 8 base62 chars

        $start = hrtime(true);
        for ($i = 0; $i < 10_000; $i++) {
            $value = random_int(0, $maxBase62);
            $encoded = HybridIdGenerator::encodeBase62($value, 8);
            $decoded = HybridIdGenerator::decodeBase62($encoded);

            // Correctness check inside the loop to catch subtle regressions
            if ($decoded !== $value) {
                $this->fail(sprintf(
                    'Base62 round-trip failed: %d → "%s" → %d',
                    $value,
                    $encoded,
                    $decoded,
                ));
            }
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertLessThan(200, $elapsedMs, sprintf(
            'base62 round-trip x10000 took %.1f ms — expected < 200ms',
            $elapsedMs,
        ));
    }

    // -------------------------------------------------------------------------
    // Profile comparison — ensure compact is not slower than extended
    // -------------------------------------------------------------------------

    public function testCompactNotSlowerThanExtended(): void
    {
        $compact = new HybridIdGenerator(profile: 'compact');
        $extended = new HybridIdGenerator(profile: 'extended', node: 'A1');

        // Warm up
        $compact->generate();
        $extended->generate();

        $start = hrtime(true);
        for ($i = 0; $i < 1_000; $i++) {
            $compact->generate();
        }
        $compactMs = (hrtime(true) - $start) / 1_000_000;

        $start = hrtime(true);
        for ($i = 0; $i < 1_000; $i++) {
            $extended->generate();
        }
        $extendedMs = (hrtime(true) - $start) / 1_000_000;

        // Compact should not be more than 3x slower than extended
        // (generous threshold to avoid flaky CI)
        $this->assertLessThan($extendedMs * 3, $compactMs, sprintf(
            'compact (%.1f ms) was unexpectedly slower than extended (%.1f ms)',
            $compactMs,
            $extendedMs,
        ));
    }
}
