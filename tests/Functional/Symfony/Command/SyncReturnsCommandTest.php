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
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class SyncReturnsCommandTest extends TestCase
{
    public function testItSynchronisesTheReturnsOfTheConfiguredTenants(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $harness->clock->modify('+1 minute');

        $command = $harness->command('urssaf:dpae:sync-returns');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame(['Tenant', 'Discovered', 'Windows', 'Outcome'], $rows[0]);
        static::assertSame(['tenant-1', '1', '1', 'synchronised'], $rows[1]);
        static::assertSame(['tenant-2', '0', '1', 'synchronised'], $rows[2]);
    }

    public function testTheAliasAndOneTenant(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));

        $command = $harness->command('urssaf:dpae:sync');
        static::assertSame(0, $command->execute(['--tenant' => 'tenant-2']));

        $rows = Output::rows($command->getDisplay());
        static::assertCount(2, $rows);
        static::assertSame('tenant-2', $rows[1][0]);
    }

    public function testADryRunListsTheTenantsWithoutCallingUrssaf(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));

        $command = $harness->command('urssaf:dpae:sync-returns');
        $status = $command->execute(['--dry-run' => true]);

        static::assertSame(0, $status);
        static::assertSame([['Tenant'], ['tenant-1'], ['tenant-2']], Output::rows($command->getDisplay()));
        static::assertStringContainsString('Dry run: 2 tenant(s) would be processed, URSSAF was not called.', $command->getDisplay());
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testARefusedPollingIsReportedAndTheNextRunCarriesOn(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(429, 30));

        $refused = $harness->command('urssaf:dpae:sync-returns');
        static::assertSame(0, $refused->execute([]));
        static::assertSame('refused, retry in 30s', Output::rows($refused->getDisplay())[1][3]);

        $next = $harness->command('urssaf:dpae:sync-returns');
        static::assertSame(0, $next->execute([]));
        static::assertSame('synchronised', Output::rows($next->getDisplay())[1][3]);
    }

    public function testATenantThatNeedsAttentionMakesTheCommandFail(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['workflow' => ['max_polling_constraint_retries' => 2]]));
        $simulator = $harness->get(SimulatedDpaeGateway::class);
        $simulator->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(422, null));
        $simulator->failNext(Operation::ListReturns, PollingConstraintViolated::withStatus(422, null));

        static::assertSame(0, $harness->command('urssaf:dpae:sync-returns')->execute([]));
        $second = $harness->command('urssaf:dpae:sync-returns');
        static::assertSame(1, $second->execute([]));

        static::assertSame('refused, retry in 60s (needs attention)', Output::rows($second->getDisplay())[1][3]);
    }

    public function testAnUnavailableUrssafFailsTheTenantButNotTheOthers(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::ListReturns, UrssafUnavailable::whileAuthenticating(Operation::ListReturns, 503, null, null));

        $command = $harness->command('urssaf:dpae:sync-returns');
        $status = $command->execute([]);

        static::assertSame(1, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame(['tenant-1', '', '', 'URSSAF authentication for operation list_returns is temporarily unavailable.'], $rows[1]);
        static::assertSame(['tenant-2', '0', '1', 'synchronised'], $rows[2]);
    }

    public function testAnOperationAlreadyRunningIsSkipped(): void
    {
        $harness = Harness::boot(Simulated::config([]), false, false, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.sync-returns.tenant-1');

        $command = $harness->command('urssaf:dpae:sync-returns');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        static::assertSame(['tenant-1', '', '', 'skipped (lock "urssaf_dpae.sync-returns.tenant-1" is held)'], Output::rows($command->getDisplay())[1]);
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
        $held->release();
    }

    public function testTheTenantsToProcessAreRequired(): void
    {
        $harness = Harness::bootDefault(['credentials' => null, 'credentials_provider' => 'test.credentials']);

        $command = $harness->command('urssaf:dpae:sync-returns');
        static::assertSame(1, $command->execute([]));
        static::assertStringContainsString('No tenant to process: use --tenant=<id> or configure "urssaf_dpae.tenants".', $command->getDisplay());
    }
}
