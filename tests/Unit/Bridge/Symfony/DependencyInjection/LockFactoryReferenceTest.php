<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\LockRegistrar;

final class LockFactoryReferenceTest extends TestCase
{
    public function testTheLockIsOffWhenDisabledOrWhenTheComponentIsMissing(): void
    {
        static::assertNotInstanceOf(Reference::class, LockRegistrar::factory(['enabled' => false, 'factory' => 'f', 'ttl' => 1.0], true));
        static::assertNotInstanceOf(Reference::class, LockRegistrar::factory(['enabled' => 'auto', 'factory' => 'f', 'ttl' => 1.0], false));
        static::assertNotInstanceOf(Reference::class, LockRegistrar::factory(['enabled' => true, 'factory' => 'f', 'ttl' => 1.0], false));
    }

    public function testAnEnabledLockRequiresTheFactoryService(): void
    {
        $reference = LockRegistrar::factory(['enabled' => true, 'factory' => 'my.lock_factory', 'ttl' => 1.0], true);

        static::assertInstanceOf(Reference::class, $reference);
        static::assertSame('my.lock_factory', (string) $reference);
        static::assertSame(ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
    }

    public function testTheAutomaticModeToleratesAMissingFactoryService(): void
    {
        $reference = LockRegistrar::factory(['enabled' => 'auto', 'factory' => 'lock.factory', 'ttl' => 1.0], true);

        static::assertInstanceOf(Reference::class, $reference);
        static::assertSame('lock.factory', (string) $reference);
        static::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
    }
}
