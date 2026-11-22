<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Handlers;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

/**
 * The lock of a deposit is named from the validated tenant and reference, with the dots escaped.
 */
final class DepositLockNamesTest extends TestCase
{
    public function testTheLockNameOfADepositEscapesTheDotsOfTheReference(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.deposit.tenant-1.ref%2E1');

        try {
            Handlers::deposit($harness)(Handlers::message('ref.1'));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            static::assertSame('Operation already running (lock "urssaf_dpae.deposit.tenant-1.ref%2E1" is held).', $failure->getMessage());
        }
        $held->release();
    }

    public function testAnInvalidReferenceIsRefusedBeforeAnyLockIsTaken(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.deposit.tenant-1.');

        try {
            Handlers::deposit($harness)(Handlers::message(''));
            static::fail('An unrecoverable failure was expected.');
        } catch (UnrecoverableMessageHandlingException $failure) {
            static::assertInstanceOf(InvalidValue::class, $failure->getPrevious());
        }
        $held->release();
    }
}
