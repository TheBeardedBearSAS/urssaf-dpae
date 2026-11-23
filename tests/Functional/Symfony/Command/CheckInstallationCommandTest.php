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
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Responses;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class CheckInstallationCommandTest extends TestCase
{
    public function testItReportsTheModeTheHostsTheCredentialsAndTheInfrastructure(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['lock' => ['enabled' => false]]));

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        static::assertSame([
            ['Check', 'Subject', 'Status', 'Detail'],
            ['custom', '', 'ok', 'Run by the host.'],
            ['declaration mode', '', 'ok', 'test: real declarations are refused.'],
            ['hosts', 'tenant-1', 'ok', 'authentication=https://mon.urssaf.fr deposit=https://depot.dpae-edi.urssaf.fr consultation=https://consultation.dpae-edi.urssaf.fr download=https://telechargement.dpae-edi.urssaf.fr'],
            ['credentials', 'tenant-1', 'ok', 'Credentials found.'],
            ['gateway', '', 'warning', 'Simulated gateway ("gateway.simulated"): nothing is sent to URSSAF.'],
            ['token store', '', 'ok', 'In memory, per process: every process authenticates once (ADR 0001).'],
            ['lock', '', 'warning', 'Disabled: overlapping runs are only protected by the claims (install symfony/lock, see docs/symfony.md).'],
        ], Output::rows($command->getDisplay()));
        static::assertStringContainsString('The URSSAF DPAE integration is ready.', $command->getDisplay());
    }

    public function testTheHostsOfAConcentratorCarryThePort(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['credentials' => ['tenant-1' => ['service' => 98]]]));

        $command = $harness->command('urssaf:dpae:check');

        static::assertSame(0, $command->execute([]));
        static::assertStringContainsString('deposit=https://depot.dpae-edi.urssaf.fr:8443 consultation=https://consultation.dpae-edi.urssaf.fr:8443', $command->getDisplay());
    }

    public function testTheRealModeIsAWarningAndAnEnabledLockIsOk(): void
    {
        $harness = Harness::boot(Simulated::config(['declaration_mode' => 'test']), false, false, true);
        $real = Harness::bootDefault(['declaration_mode' => 'real', 'token_store' => ['service' => 'test.token_store']]);

        $locked = $harness->command('urssaf:dpae:check');
        $locked->execute([]);
        $warned = $real->command('urssaf:dpae:check');
        $warned->execute(['--skip-remote' => true]);

        static::assertStringContainsString('| lock', $locked->getDisplay());
        static::assertContains(['lock', '', 'ok', 'Enabled: use a store shared by every host that runs the crons and workers.'], Output::rows($locked->getDisplay()));
        static::assertContains(['declaration mode', '', 'warning', 'real: this installation may deposit real declarations, which have legal effects.'], Output::rows($warned->getDisplay()));
        static::assertContains(['token store', '', 'ok', 'Service "test.token_store": the token is a credential, keep it out of logs and backups.'], Output::rows($warned->getDisplay()));
    }

    public function testItAuthenticatesEveryTenantWithoutPrintingTheToken(): void
    {
        $harness = Harness::bootDefault(['tenants' => ['tenant-1']]);
        $harness->client->addResponse(Responses::token());

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        static::assertContains(['authentication', 'tenant-1', 'ok', 'Token granted by URSSAF.'], Output::rows($command->getDisplay()));
        static::assertStringNotContainsString(Responses::TOKEN, $command->getDisplay());
        static::assertStringNotContainsString(TestKernel::SECRET, $command->getDisplay());
        $requests = $harness->client->getRequests();
        static::assertCount(1, $requests);
        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $requests[0]->getUri());
    }

    public function testAnAuthenticationRefusedByUrssafFailsTheCheck(): void
    {
        $harness = Harness::bootDefault([]);
        $harness->client->addResponse(Responses::status(401));

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute([]);

        static::assertSame(1, $status);
        static::assertStringContainsString('| authentication', $command->getDisplay());
        static::assertStringContainsString('failure', $command->getDisplay());
        static::assertStringContainsString('1 check(s) failed.', $command->getDisplay());
    }

    public function testTheRemoteCheckCanBeSkipped(): void
    {
        $harness = Harness::bootDefault([]);

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute(['--skip-remote' => true]);

        static::assertSame(0, $status);
        static::assertContains(['authentication', '', 'skipped', 'Not checked (--skip-remote).'], Output::rows($command->getDisplay()));
        static::assertSame([], $harness->client->getRequests());
    }

    public function testATenantWithoutCredentialsFailsTheCheck(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['credentials' => null, 'credentials_provider' => 'test.credentials', 'tenants' => ['ghost']]));

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute([]);

        static::assertSame(1, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame('credentials', $rows[3][0]);
        static::assertSame(['ghost', 'failure'], \array_slice($rows[3], 1, 2));
        static::assertCount(7, $rows);
    }

    public function testTheCheckedTenantCanBeChosen(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));

        $command = $harness->command('urssaf:dpae:check');
        $command->execute(['--tenant' => 'tenant-1']);

        static::assertStringContainsString('tenant-1', $command->getDisplay());
        static::assertStringNotContainsString('tenant-2', $command->getDisplay());
    }
}
