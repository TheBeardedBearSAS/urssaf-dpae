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
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;

final class DepositCommandTest extends TestCase
{
    private Workspace $files;

    protected function setUp(): void
    {
        $this->files = new Workspace();
    }

    public function testItDepositsTheFlowAsIs(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(0, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame(['Deposit', 'Reference', 'Mode', 'State', 'Flux', 'Attempts', 'Last error'], $rows[0]);
        static::assertSame(['ref-1', 'test', 'received'], \array_slice($rows[1], 1, 3));
        static::assertNotSame('', $rows[1][4]);
        static::assertSame('1', $rows[1][5]);
        static::assertSame('', $rows[1][6]);
        static::assertCount(1, $harness->get(SimulatedDpaeGateway::class)->deposited());
    }

    public function testARejectedDepositMakesTheCommandFail(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $harness->get(SimulatedDpaeGateway::class)->rejectNextDeposit(['bad']);
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(1, $status);
        static::assertSame('rejected', Output::rows($command->getDisplay())[1][3]);
    }

    public function testAnUnavailableUrssafFailsTheCommandAndTheNextRunRetries(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::Deposit, UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, null, null));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(1, $status);
        static::assertStringContainsString('temporarily unavailable', $command->getDisplay());
        $retry = $harness->command('urssaf:dpae:deposit');
        static::assertSame(0, $retry->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']));
        static::assertSame(['received', '2'], [Output::rows($retry->getDisplay())[1][3], Output::rows($retry->getDisplay())[1][5]]);
    }

    public function testRefusedCredentialsParkTheDepositWithTheCodeOfTheFailure(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $harness->get(SimulatedDpaeGateway::class)->failNext(Operation::Deposit, UrssafAuthenticationFailed::because(AuthenticationFailure::InvalidCredentials, 'realm'));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(1, $status);
        $row = Output::rows($command->getDisplay())[1];
        static::assertSame(['invalid_credentials', '', 'deposit.invalid_credentials'], [$row[3], $row[4], $row[6]]);
    }

    public function testALostAnswerIsAnUnknownOutcomeThatIsNeverResent(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $simulator = $harness->get(SimulatedDpaeGateway::class);
        $simulator->loseNextAnswer();
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $first = $harness->command('urssaf:dpae:deposit');
        static::assertSame(1, $first->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']));
        $second = $harness->command('urssaf:dpae:deposit');
        $second->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertCount(1, $simulator->deposited());
    }

    public function testAnOperationAlreadyRunningIsSkipped(): void
    {
        $harness = Harness::boot(Simulated::config([]), false, false, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.deposit.tenant-1.ref-1');
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(0, $status);
        static::assertStringContainsString('Already running (lock "urssaf_dpae.deposit.tenant-1.ref-1" is held): skipped.', $command->getDisplay());
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
        $held->release();
        static::assertSame(0, $this->deposit($harness, $file));
    }

    private function deposit(Harness $harness, string $file): int
    {
        return $harness->command('urssaf:dpae:deposit')->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);
    }
}
