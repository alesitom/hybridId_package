<?php

declare(strict_types=1);

namespace HybridId\Tests\Testing;

use HybridId\HybridIdGenerator;
use HybridId\IdGenerator;
use HybridId\Testing\MockHybridIdGenerator;
use PHPUnit\Framework\TestCase;

final class MockHybridIdGeneratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface compliance
    // -------------------------------------------------------------------------

    public function testImplementsIdGenerator(): void
    {
        $mock = new MockHybridIdGenerator(['AAAAAAAAAAAAAAAAAAAA']);

        $this->assertInstanceOf(IdGenerator::class, $mock);
    }

    // -------------------------------------------------------------------------
    // generate()
    // -------------------------------------------------------------------------

    public function testGenerateReturnsIdsInSequence(): void
    {
        $ids = ['id_one_1234567890AB', 'id_two_1234567890AB'];
        $mock = new MockHybridIdGenerator($ids);

        $this->assertSame('id_one_1234567890AB', $mock->generate());
        $this->assertSame('id_two_1234567890AB', $mock->generate());
    }

    public function testGenerateAcceptsPrefixedId(): void
    {
        $mock = new MockHybridIdGenerator(['usr_exactlyThisId123456']);

        $this->assertSame('usr_exactlyThisId123456', $mock->generate('usr'));
    }

    public function testGenerateThrowsWhenPrefixMismatch(): void
    {
        $mock = new MockHybridIdGenerator(['exactlyThisId123456']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not start with "usr_"');

        $mock->generate('usr');
    }

    public function testGenerateWithoutPrefixStillWorks(): void
    {
        $mock = new MockHybridIdGenerator(['exactlyThisId123456']);

        $this->assertSame('exactlyThisId123456', $mock->generate());
    }

    public function testGenerateThrowsWhenExhausted(): void
    {
        $mock = new MockHybridIdGenerator(['onlyOne12345678901']);
        $mock->generate();

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('exhausted');

        $mock->generate();
    }

    // -------------------------------------------------------------------------
    // generateBatch()
    // -------------------------------------------------------------------------

    public function testGenerateBatchReturnsCorrectCount(): void
    {
        $ids = ['aaa1234567890ABCDE', 'bbb1234567890ABCDE', 'ccc1234567890ABCDE'];
        $mock = new MockHybridIdGenerator($ids);

        $batch = $mock->generateBatch(3);

        $this->assertSame($ids, $batch);
    }

    public function testGenerateBatchThrowsOnInvalidCount(): void
    {
        $mock = new MockHybridIdGenerator(['test12345678901234']);

        $this->expectException(\InvalidArgumentException::class);

        $mock->generateBatch(0);
    }

    public function testGenerateBatchThrowsOnExcessiveCount(): void
    {
        $mock = new MockHybridIdGenerator(['test12345678901234']);

        $this->expectException(\InvalidArgumentException::class);

        $mock->generateBatch(10_001);
    }

    public function testGenerateBatchAdvancesCursor(): void
    {
        $ids = ['aaa1234567890ABCDE', 'bbb1234567890ABCDE', 'ccc1234567890ABCDE'];
        $mock = new MockHybridIdGenerator($ids);

        $mock->generateBatch(2);

        $this->assertSame(1, $mock->remaining());
        $this->assertSame('ccc1234567890ABCDE', $mock->generate());
    }

    // -------------------------------------------------------------------------
    // bodyLength()
    // -------------------------------------------------------------------------

    public function testBodyLengthDefaultIs20(): void
    {
        $mock = new MockHybridIdGenerator(['test12345678901234']);

        $this->assertSame(20, $mock->bodyLength());
    }

    public function testBodyLengthIsConfigurable(): void
    {
        $mock = new MockHybridIdGenerator(['test123456789012'], bodyLength: 16);

        $this->assertSame(16, $mock->bodyLength());
    }

    // -------------------------------------------------------------------------
    // validate()
    // -------------------------------------------------------------------------

    public function testValidateAcceptsValidIds(): void
    {
        $gen = new HybridIdGenerator(node: 'A1');
        $id = $gen->generate();

        $mock = new MockHybridIdGenerator([$id]);

        $this->assertTrue($mock->validate($id));
    }

    public function testValidateRejectsInvalidIds(): void
    {
        $mock = new MockHybridIdGenerator(['test12345678901234']);

        $this->assertFalse($mock->validate('invalid'));
    }

    public function testValidateChecksPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');

        $mock = new MockHybridIdGenerator([$id]);

        $this->assertTrue($mock->validate($id, 'usr'));
        $this->assertFalse($mock->validate($id, 'ord'));
    }

    public function testValidateAcceptsNullPrefix(): void
    {
        $gen = new HybridIdGenerator(requireExplicitNode: false);
        $id = $gen->generate('usr');

        $mock = new MockHybridIdGenerator([$id]);

        $this->assertTrue($mock->validate($id));
    }

    // -------------------------------------------------------------------------
    // remaining()
    // -------------------------------------------------------------------------

    public function testRemainingTracksConsumption(): void
    {
        $mock = new MockHybridIdGenerator(['a1234567890123456789', 'b1234567890123456789', 'c1234567890123456789']);

        $this->assertSame(3, $mock->remaining());

        $mock->generate();
        $this->assertSame(2, $mock->remaining());

        $mock->generate();
        $this->assertSame(1, $mock->remaining());

        $mock->generate();
        $this->assertSame(0, $mock->remaining());
    }

    // -------------------------------------------------------------------------
    // reset()
    // -------------------------------------------------------------------------

    public function testResetRestartsCursor(): void
    {
        $mock = new MockHybridIdGenerator(['first1234567890123', 'second123456789012']);

        $this->assertSame('first1234567890123', $mock->generate());
        $mock->reset();

        $this->assertSame(2, $mock->remaining());
        $this->assertSame('first1234567890123', $mock->generate());
    }

    // -------------------------------------------------------------------------
    // Constructor validation
    // -------------------------------------------------------------------------

    public function testConstructorRejectsEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one ID');

        new MockHybridIdGenerator([]);
    }

    // -------------------------------------------------------------------------
    // withCallback()
    // -------------------------------------------------------------------------

    public function testWithCallbackGeneratesViaCallback(): void
    {
        $mock = MockHybridIdGenerator::withCallback(
            fn(?string $prefix): string => ($prefix !== null ? $prefix . '_' : '') . str_repeat('A', 20),
        );

        $this->assertSame(str_repeat('A', 20), $mock->generate());
        $this->assertSame('usr_' . str_repeat('A', 20), $mock->generate('usr'));
    }

    public function testWithCallbackNeverExhausts(): void
    {
        $counter = 0;
        $mock = MockHybridIdGenerator::withCallback(
            function (?string $prefix) use (&$counter): string {
                return 'id' . str_pad((string) ++$counter, 18, '0', STR_PAD_LEFT);
            },
        );

        for ($i = 0; $i < 100; $i++) {
            $mock->generate();
        }

        $this->assertSame(100, $counter);
    }

    public function testWithCallbackRemainingReturnsIntMax(): void
    {
        $mock = MockHybridIdGenerator::withCallback(fn() => str_repeat('A', 20));

        $this->assertSame(PHP_INT_MAX, $mock->remaining());
    }

    public function testWithCallbackResetIsNoOp(): void
    {
        $counter = 0;
        $mock = MockHybridIdGenerator::withCallback(
            function (?string $prefix) use (&$counter): string {
                return 'id' . str_pad((string) ++$counter, 18, '0', STR_PAD_LEFT);
            },
        );

        $mock->generate();
        $mock->generate();
        $mock->reset();

        // Counter is NOT reset — reset() is a no-op in callback mode
        $this->assertSame('id000000000000000003', $mock->generate());
    }

    public function testWithCallbackBodyLength(): void
    {
        $mock = MockHybridIdGenerator::withCallback(
            fn() => str_repeat('A', 24),
            bodyLength: 24,
        );

        $this->assertSame(24, $mock->bodyLength());
    }

    public function testWithCallbackDefaultBodyLength(): void
    {
        $mock = MockHybridIdGenerator::withCallback(fn() => str_repeat('A', 20));

        $this->assertSame(20, $mock->bodyLength());
    }

    public function testWithCallbackGenerateBatchWorks(): void
    {
        $counter = 0;
        $mock = MockHybridIdGenerator::withCallback(
            function (?string $prefix) use (&$counter): string {
                $id = 'id' . str_pad((string) ++$counter, 18, '0', STR_PAD_LEFT);

                return $prefix !== null ? $prefix . '_' . $id : $id;
            },
        );

        $batch = $mock->generateBatch(3, 'usr');

        $this->assertCount(3, $batch);
        $this->assertSame('usr_id000000000000000001', $batch[0]);
        $this->assertSame('usr_id000000000000000002', $batch[1]);
        $this->assertSame('usr_id000000000000000003', $batch[2]);
    }

    public function testWithCallbackImplementsIdGenerator(): void
    {
        $mock = MockHybridIdGenerator::withCallback(fn() => str_repeat('A', 20));

        $this->assertInstanceOf(IdGenerator::class, $mock);
    }

    public function testWithCallbackThrowsOnPrefixMismatch(): void
    {
        $mock = MockHybridIdGenerator::withCallback(
            fn(?string $prefix): string => 'no_prefix_here_12345',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('prefix "usr"');

        $mock->generate('usr');
    }

    public function testWithCallbackExceptionPropagates(): void
    {
        $mock = MockHybridIdGenerator::withCallback(
            function (?string $prefix): string {
                throw new \RuntimeException('generation failed');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('generation failed');

        $mock->generate();
    }

    public function testWithCallbackBatchThrowsOnInvalidCount(): void
    {
        $mock = MockHybridIdGenerator::withCallback(fn() => str_repeat('A', 20));

        $this->expectException(\InvalidArgumentException::class);

        $mock->generateBatch(0);
    }

    public function testWithCallbackBatchThrowsOnExcessiveCount(): void
    {
        $mock = MockHybridIdGenerator::withCallback(fn() => str_repeat('A', 20));

        $this->expectException(\InvalidArgumentException::class);

        $mock->generateBatch(10_001);
    }
}
