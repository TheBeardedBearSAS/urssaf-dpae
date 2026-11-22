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
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessage;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Handlers;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\RetryDelay;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class PollingMessageHandlersTest extends TestCase
{
    public function testTheReturnsAreSynchronised(): void
    {
        $harness = Handlers::boot();

        Handlers::synchronize($harness)(new SynchronizeReturnsMessage('tenant-1'));

        static::assertSame([Operation::ListReturns], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testARefusedPollingIsRetriedAfterTheAdvisedDelay(): void
    {
        $harness = Handlers::boot();
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(429, 12));

        try {
            Handlers::synchronize($harness)(new SynchronizeReturnsMessage('tenant-1'));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(12_000, $failure);
            static::assertSame('URSSAF refused the polling of "returns".', $failure->getMessage());
            static::assertNull($failure->getPrevious());
        }
    }

    public function testASynchronisationAlreadyRunningIsRetriedLater(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.sync-returns.tenant-1');

        $this->expectException(RecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('urssaf_dpae.sync-returns.tenant-1');

        try {
            Handlers::synchronize($harness)(new SynchronizeReturnsMessage('tenant-1'));
        } finally {
            $held->release();
        }
    }

    public function testAnInvalidTenantIsUnrecoverable(): void
    {
        $harness = Handlers::boot();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        Handlers::synchronize($harness)(new SynchronizeReturnsMessage(''));
    }

    public function testTheDepositsAreReconciled(): void
    {
        $harness = Handlers::boot();

        Handlers::reconcile($harness)(new ReconcileDepositsMessage('tenant-1'));

        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testAReconciliationRefusedByUrssafIsRetriedAfterTheAdvisedDelay(): void
    {
        $harness = Handlers::boot();
        $simulator = $harness->get(SimulatedDpaeGateway::class);
        $simulator->loseNextAnswer();
        Handlers::deposit($harness)(DepositDpaeMessage::of('tenant-1', 'ref-1', DeclarationMode::Test, Flows::xml(DeclarationMode::Test, 'a')));
        $simulator->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(429, 20));

        try {
            Handlers::reconcile($harness)(new ReconcileDepositsMessage('tenant-1'));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(20_000, $failure);
            static::assertSame('URSSAF refused the polling of "reconcile".', $failure->getMessage());
        }
    }

    public function testAReconciliationAlreadyRunningIsRetriedLater(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.reconcile.tenant-1');

        $this->expectException(RecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('urssaf_dpae.reconcile.tenant-1');

        try {
            Handlers::reconcile($harness)(new ReconcileDepositsMessage('tenant-1'));
        } finally {
            $held->release();
        }
    }




}
