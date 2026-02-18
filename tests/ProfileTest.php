<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\HybridIdGenerator;
use HybridId\Profile;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testEnumCasesExist(): void
    {
        $this->assertSame('compact', Profile::Compact->value);
        $this->assertSame('standard', Profile::Standard->value);
        $this->assertSame('extended', Profile::Extended->value);
    }

    public function testEnumHasExactlyThreeCases(): void
    {
        $this->assertCount(3, Profile::cases());
    }

    public function testTryFromValidValues(): void
    {
        $this->assertSame(Profile::Compact, Profile::tryFrom('compact'));
        $this->assertSame(Profile::Standard, Profile::tryFrom('standard'));
        $this->assertSame(Profile::Extended, Profile::tryFrom('extended'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(Profile::tryFrom('ultra'));
        $this->assertNull(Profile::tryFrom(''));
    }

    public function testFromThrowsOnInvalid(): void
    {
        $this->expectException(\ValueError::class);

        Profile::from('nonexistent');
    }

    public function testConstructorAcceptsEnum(): void
    {
        $gen = new HybridIdGenerator(profile: Profile::Compact);
        $this->assertSame('compact', $gen->getProfile());

        $gen = new HybridIdGenerator(profile: Profile::Standard, node: 'T1');
        $this->assertSame('standard', $gen->getProfile());

        $gen = new HybridIdGenerator(profile: Profile::Extended, node: 'T1');
        $this->assertSame('extended', $gen->getProfile());
    }

    public function testConstructorAcceptsEnumAndStringInterchangeably(): void
    {
        $fromEnum = new HybridIdGenerator(profile: Profile::Compact);
        $fromString = new HybridIdGenerator(profile: 'compact');

        $this->assertSame($fromEnum->getProfile(), $fromString->getProfile());
        $this->assertSame($fromEnum->bodyLength(), $fromString->bodyLength());
    }

    public function testBodyLengthReturnsCorrectValues(): void
    {
        $this->assertSame(16, Profile::Compact->bodyLength());
        $this->assertSame(20, Profile::Standard->bodyLength());
        $this->assertSame(24, Profile::Extended->bodyLength());
    }

    public function testBodyLengthMatchesGeneratorBodyLength(): void
    {
        $this->assertSame(
            (new HybridIdGenerator(profile: Profile::Compact))->bodyLength(),
            Profile::Compact->bodyLength(),
        );
        $this->assertSame(
            (new HybridIdGenerator(profile: Profile::Standard, node: 'T1'))->bodyLength(),
            Profile::Standard->bodyLength(),
        );
        $this->assertSame(
            (new HybridIdGenerator(profile: Profile::Extended, node: 'T1'))->bodyLength(),
            Profile::Extended->bodyLength(),
        );
    }

    public function testConfigReturnsProfileConfigArray(): void
    {
        foreach (Profile::cases() as $profile) {
            $config = $profile->config();

            $this->assertSame(
                HybridIdGenerator::profileConfig($profile),
                $config,
            );
            $this->assertArrayHasKey('length', $config);
            $this->assertArrayHasKey('node', $config);
            $this->assertArrayHasKey('random', $config);
            $this->assertSame($profile->bodyLength(), $config['length']);
        }
    }

    public function testStaticMethodsAcceptEnum(): void
    {
        $ts = (int) (microtime(true) * 1000);

        $this->assertSame(
            HybridIdGenerator::profileConfig('compact'),
            HybridIdGenerator::profileConfig(Profile::Compact),
        );

        $this->assertSame(
            HybridIdGenerator::entropy('standard'),
            HybridIdGenerator::entropy(Profile::Standard),
        );

        $this->assertSame(
            HybridIdGenerator::recommendedColumnSize('extended'),
            HybridIdGenerator::recommendedColumnSize(Profile::Extended),
        );

        $this->assertSame(
            HybridIdGenerator::minForTimestamp($ts, 'compact'),
            HybridIdGenerator::minForTimestamp($ts, Profile::Compact),
        );

        $this->assertSame(
            HybridIdGenerator::maxForTimestamp($ts, 'standard'),
            HybridIdGenerator::maxForTimestamp($ts, Profile::Standard),
        );
    }
}
