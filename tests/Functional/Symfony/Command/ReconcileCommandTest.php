<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class ReconcileCommandTest extends TestCase
{
    public function testItAdoptsTheFluxOfADepositWhoseAnswerWasLost(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $this->loseAnswer($harness);
        $harness->clock->modify('+2 hours');

        $command = $harness->command('urssaf:dpae:reconcile');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame(['Tenant', 'Marked unknown', 'Adopted', 'Needing review', 'Unattached flux', 'Outcome'], $rows[0]);
        static::assertSame(['tenant-1', '0', '1', '0', '0', 'reconciled'], $rows[1]);
        static::assertCount(1, $harness->get(SimulatedDpaeGateway::class)->deposited());
    }

    public function testADepositThatNeedsAHumanMakesTheCommandFail(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['workflow' => ['auto_adopt' => false]]));
        $this->loseAnswer($harness);
        $harness->clock->modify('+2 hours');

        $command = $harness->command('urssaf:dpae:reconcile');
        $status = $command->execute([]);

        static::assertSame(1, $status);
        static::assertSame(['tenant-1', '0', '0', '1', '0', 'reconciled'], Output::rows($command->getDisplay())[1]);
    }

    public function testARefusedPollingIsReported(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $this->loseAnswer($harness);
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(429, 45));
        $harness->clock->modify('+2 hours');

        $command = $harness->command('urssaf:dpae:reconcile');

        static::assertSame(0, $command->execute([]));
        static::assertSame('refused, retry in 45s', Output::rows($command->getDisplay())[1][5]);
    }

    public function testAnUnavailableUrssafFailsTheTenant(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $this->loseAnswer($harness);
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::ListReturns, UrssafUnavailable::whileAuthenticating(Operation::ListReturns, 503, null, null));
        $harness->clock->modify('+2 hours');

        $command = $harness->command('urssaf:dpae:reconcile');

        static::assertSame(1, $command->execute(['--tenant' => 'tenant-1']));
        static::assertSame(['tenant-1', '', '', '', '', 'URSSAF authentication for operation list_returns is temporarily unavailable.'], Output::rows($command->getDisplay())[1]);
    }

    public function testADryRunListsTheTenants(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));

        $command = $harness->command('urssaf:dpae:reconcile');

        static::assertSame(0, $command->execute(['--dry-run' => true]));
        static::assertSame([['Tenant'], ['tenant-1']], Output::rows($command->getDisplay()));
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testAnOperationAlreadyRunningIsSkipped(): void
    {
        $harness = Harness::boot(Simulated::config([]), false, false, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.reconcile.tenant-1');

        $command = $harness->command('urssaf:dpae:reconcile');

        static::assertSame(0, $command->execute([]));
        static::assertSame(['tenant-1', '', '', '', '', 'skipped (lock "urssaf_dpae.reconcile.tenant-1" is held)'], Output::rows($command->getDisplay())[1]);
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
        $held->release();
    }

    private function loseAnswer(Harness $harness): void
    {
        $harness->get(SimulatedDpaeGateway::class)->loseNextAnswer();
        $deposit = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
    }
}
