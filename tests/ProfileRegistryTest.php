<?php

declare(strict_types=1);

namespace HybridId\Tests;

use HybridId\Exception\InvalidProfileException;
use HybridId\HybridIdGenerator;
use HybridId\ProfileRegistry;
use HybridId\ProfileRegistryInterface;
use PHPUnit\Framework\TestCase;

final class ProfileRegistryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface compliance
    // -------------------------------------------------------------------------

    public function testImplementsInterface(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $this->assertInstanceOf(ProfileRegistryInterface::class, $registry);
    }

    // -------------------------------------------------------------------------
    // Built-in profiles
    // -------------------------------------------------------------------------

    public function testWithDefaultsHasThreeBuiltInProfiles(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->assertSame(['compact', 'standard', 'extended'], $registry->all());
    }

    public function testGetBuiltInProfiles(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $compact = $registry->get('compact');
        $this->assertNotNull($compact);
        $this->assertSame(16, $compact['length']);
        $this->assertSame(6, $compact['random']);

        $standard = $registry->get('standard');
        $this->assertNotNull($standard);
        $this->assertSame(20, $standard['length']);
        $this->assertSame(10, $standard['random']);

        $extended = $registry->get('extended');
        $this->assertNotNull($extended);
        $this->assertSame(24, $extended['length']);
        $this->assertSame(14, $extended['random']);
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->assertNull($registry->get('nonexistent'));
    }

    public function testGetByLengthReturnsCorrectProfile(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->assertSame('compact', $registry->getByLength(16));
        $this->assertSame('standard', $registry->getByLength(20));
        $this->assertSame('extended', $registry->getByLength(24));
    }

    public function testGetByLengthReturnsNullForUnknown(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->assertNull($registry->getByLength(99));
    }

    // -------------------------------------------------------------------------
    // Custom profile registration
    // -------------------------------------------------------------------------

    public function testRegisterCustomProfile(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('ultra', 22);

        $config = $registry->get('ultra');
        $this->assertNotNull($config);
        $this->assertSame(32, $config['length']); // 8 + 2 + 22
        $this->assertSame(22, $config['random']);
        $this->assertSame(8, $config['ts']);
        $this->assertSame(2, $config['node']);
    }

    public function testCustomProfileAppearsInAll(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('ultra', 22);

        $this->assertContains('ultra', $registry->all());
    }

    public function testCustomProfileGetByLength(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('ultra', 22);

        $this->assertSame('ultra', $registry->getByLength(32));
    }

    // -------------------------------------------------------------------------
    // Registration validation
    // -------------------------------------------------------------------------

    public function testRegisterRejectsDuplicateName(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessage('already exists');

        $registry->register('compact', 10);
    }

    public function testRegisterRejectsLengthConflict(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessage('conflicts with');

        // standard = 20 total = 8 + 2 + 10 random, so random=10 conflicts
        $registry->register('myprofile', 10);
    }

    public function testRegisterRejectsRandomBelowMinimum(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessage('between 6 and 128');

        $registry->register('weak', 5);
    }

    public function testRegisterRejectsRandomAboveMaximum(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessage('between 6 and 128');

        $registry->register('huge', 129);
    }

    public function testRegisterRejectsZeroRandom(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);

        $registry->register('norandom', 0);
    }

    public function testRegisterRejectsInvalidName(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessage('lowercase alphanumeric');

        $registry->register('My-Profile', 22);
    }

    public function testRegisterRejectsUppercaseName(): void
    {
        $registry = ProfileRegistry::withDefaults();

        $this->expectException(InvalidProfileException::class);

        $registry->register('Ultra', 22);
    }

    // -------------------------------------------------------------------------
    // Reset
    // -------------------------------------------------------------------------

    public function testResetClearsCustomProfiles(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('ultra', 22);
        $registry->reset();

        $this->assertNull($registry->get('ultra'));
        $this->assertNull($registry->getByLength(32));
        $this->assertSame(['compact', 'standard', 'extended'], $registry->all());
    }

    public function testResetPreservesBuiltInProfiles(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->reset();

        $this->assertNotNull($registry->get('compact'));
        $this->assertNotNull($registry->get('standard'));
        $this->assertNotNull($registry->get('extended'));
    }

    // -------------------------------------------------------------------------
    // Injection into HybridIdGenerator
    // -------------------------------------------------------------------------

    public function testInjectionIntoConstructor(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('ultra', 22);

        $gen = new HybridIdGenerator(profile: 'ultra', registry: $registry);

        $this->assertSame('ultra', $gen->getProfile());
        $this->assertSame(32, $gen->bodyLength());
        $this->assertSame(32, strlen($gen->generate()));
    }

    public function testInjectedRegistryIsolation(): void
    {
        $registry1 = ProfileRegistry::withDefaults();
        $registry1->register('custom1', 18);

        $registry2 = ProfileRegistry::withDefaults();
        $registry2->register('custom2', 22);

        $gen1 = new HybridIdGenerator(profile: 'custom1', registry: $registry1);
        $gen2 = new HybridIdGenerator(profile: 'custom2', registry: $registry2);

        $this->assertSame(28, $gen1->bodyLength());
        $this->assertSame(32, $gen2->bodyLength());
    }

    public function testInjectedRegistryDoesNotAffectGlobal(): void
    {
        $registry = ProfileRegistry::withDefaults();
        $registry->register('isolated', 22);

        new HybridIdGenerator(profile: 'isolated', registry: $registry);

        // Global registry should not have 'isolated'
        $this->assertNotContains('isolated', HybridIdGenerator::profiles());
    }
}
