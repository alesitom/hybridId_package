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

    public function testGenerateIgnoresPrefix(): void
    {
        $mock = new MockHybridIdGenerator(['exactlyThisId123456']);

        $this->assertSame('exactlyThisId123456', $mock->generate('usr'));
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

        $mock->generateBatch(100_001);
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
}
