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
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessage;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Handlers;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\RetryDelay;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class MessageHandlersTest extends TestCase
{
    public function testTheDepositMessageDepositsTheFlow(): void
    {
        $harness = Handlers::boot();

        Handlers::deposit($harness)(Handlers::message('ref-1'));

        static::assertCount(1, $harness->get(SimulatedDpaeGateway::class)->deposited());
    }

    public function testARejectionIsADepositStateNotAFailureOfTheMessage(): void
    {
        $harness = Handlers::boot();
        $harness->get(SimulatedDpaeGateway::class)->rejectNextDeposit(['bad']);

        Handlers::deposit($harness)(Handlers::message('ref-1'));

        static::assertCount(1, $harness->get(SimulatedDpaeGateway::class)->deposited());
    }

    public function testTheSameMessageIsNotDepositedTwice(): void
    {
        $harness = Handlers::boot();

        Handlers::deposit($harness)(Handlers::message('ref-1'));
        Handlers::deposit($harness)(Handlers::message('ref-1'));

        static::assertCount(1, $harness->get(SimulatedDpaeGateway::class)->deposited());
    }

    public function testATemporaryFailureIsRecoverableWithTheRetryDelayOfUrssaf(): void
    {
        $harness = Handlers::boot();
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::Deposit, UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, 7, null));

        try {
            Handlers::deposit($harness)(Handlers::message('ref-1'));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(7000, $failure);
            static::assertInstanceOf(UrssafUnavailable::class, $failure->getPrevious());
            static::assertSame($failure->getPrevious()->getMessage(), $failure->getMessage());
            static::assertSame(0, $failure->getCode());
        }
    }

    public function testARealFlowIsUnrecoverableForATestInstallation(): void
    {
        $harness = Handlers::boot();

        try {
            Handlers::deposit($harness)(DepositDpaeMessage::of('tenant-1', 'ref-1', DeclarationMode::Real, Flows::xml(DeclarationMode::Real, 'a')));
            static::fail('An unrecoverable failure was expected.');
        } catch (UnrecoverableMessageHandlingException $failure) {
            static::assertStringContainsString('does not allow', $failure->getMessage());
            static::assertSame(0, $failure->getCode());
            static::assertNotNull($failure->getPrevious());
        }
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testAFlowThatDoesNotMatchTheModeIsUnrecoverable(): void
    {
        $harness = Handlers::boot();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        Handlers::deposit($harness)(DepositDpaeMessage::of('tenant-1', 'ref-1', DeclarationMode::Test, Flows::xml(DeclarationMode::Real, 'a')));
    }

    public function testAnUnknownModeAndAMalformedFlowAreUnrecoverable(): void
    {
        $harness = Handlers::boot();

        try {
            Handlers::deposit($harness)(new DepositDpaeMessage('tenant-1', 'ref-1', 'production', base64_encode(Flows::xml(DeclarationMode::Test, 'a'))));
            static::fail('An unknown mode must be refused.');
        } catch (UnrecoverableMessageHandlingException $failure) {
            static::assertSame('The mode of the message must be "test" or "real".', $failure->getMessage());
            static::assertSame(0, $failure->getCode());
        }
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('The flow of the message is not base 64 encoded.');

        Handlers::deposit($harness)(new DepositDpaeMessage('tenant-1', 'ref-1', 'test', '%%%'));
    }

    public function testAnInvalidReferenceIsUnrecoverable(): void
    {
        $harness = Handlers::boot();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        Handlers::deposit($harness)(Handlers::message(''));
    }

    public function testADepositAlreadyRunningIsRetriedLater(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.deposit.tenant-1.ref-1');

        try {
            Handlers::deposit($harness)(Handlers::message('ref-1'));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(30_000, $failure);
            static::assertSame('Operation already running (lock "urssaf_dpae.deposit.tenant-1.ref-1" is held).', $failure->getMessage());
        }
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
        $held->release();
    }





}
