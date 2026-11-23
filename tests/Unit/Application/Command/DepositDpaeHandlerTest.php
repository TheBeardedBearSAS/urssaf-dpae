<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived as PublicReceived;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DepositDpaeHandlerTest extends TestCase
{
    use DepositHandlerFixtures;

    public function testAReceiptIsRecordedAfterAnAtomicClaimMadeBeforeTheSend(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $states = [];
        $harness->gateway->onDeposit(static function () use ($harness, &$states): void {
            $deposit = $harness->deposits->findOpenByReference(Samples::tenant('tenant-1'), Samples::reference('ref-1'));
            $states[] = $deposit?->state();
            $harness->journal->note('send');
        });

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame([DepositState::Depositing], $states);
        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertSame('id-1', $deposit->id()->value());
        static::assertSame(1, $deposit->attempts());
        static::assertSame(Samples::NOW, $deposit->claimedAt()->toIso8601());
        static::assertSame(Samples::NOW, $deposit->updatedAt()->toIso8601());
        static::assertSame(DeclarationMode::Test, $deposit->mode());
        static::assertSame(Samples::SIRET, $deposit->declarantSiret()?->value());
        static::assertTrue($deposit->fingerprint()->equals(Samples::fingerprint('a')));
        static::assertSame($deposit, $harness->deposits->get($deposit->id()));
        static::assertSame(['begin', 'outbox', 'commit', 'publish', 'send', 'begin', 'outbox', 'commit', 'publish'], $harness->journal->entries());
        static::assertCount(1, $harness->events->events());
        static::assertInstanceOf(DepositReceived::class, $harness->events->events()[0]);
        static::assertCount(1, $harness->outbox->events());
        $published = $harness->outbox->events()[0];
        static::assertInstanceOf(PublicReceived::class, $published);
        static::assertSame('F1', $published->flowId);
    }

    public function testARejectionNoticeClosesTheDeposit(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::rejection('F2'));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertFalse($deposit->isOpen());
        static::assertSame('F2', $deposit->flowId()?->value());
    }

    public function testAConcentratorDepositDoesNotKnowItsDeclarantSiret(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-c'));

        static::assertNull($deposit->declarantSiret());
        static::assertSame('tenant-c', $harness->gateway->depositedFor()[0]->value());
    }

    public function testTheGatewayReceivesTheTenantAndTheFlowAsGiven(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $command = self::command('a', 'ref-1', 'tenant-1');

        $harness->depositHandler(DeclarationMode::Test)($command);

        static::assertSame('tenant-1', $harness->gateway->depositedFor()[0]->value());
        static::assertSame($command->flow, $harness->gateway->depositedFlows()[0]);
    }

    public function testARealFlowIsRefusedBeforeAnythingInATestInstallation(): void
    {
        $harness = self::harness();
        $command = new DepositDpae(Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flow(DeclarationMode::Real, 'a'));

        try {
            $harness->depositHandler(DeclarationMode::Test)($command);
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('declaration_mode.not_allowed', $e->violationCode());
            static::assertSame(['mode' => 'real', 'installation_mode' => 'test'], $e->params());
        }

        static::assertSame([], $harness->journal->entries());
        static::assertSame([], $harness->gateway->depositedFor());
        static::assertNull($harness->deposits->findOpenByReference(Samples::tenant('tenant-1'), Samples::reference('ref-1')));
    }

    public function testARealFlowIsDepositedInARealInstallationAndATestFlowToo(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $harness->gateway->willDeposit(SampleAnswers::receipt('F2'));
        $handler = $harness->depositHandler(DeclarationMode::Real);

        $real = $handler(new DepositDpae(Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flow(DeclarationMode::Real, 'a')));
        $test = $handler(self::command('b', 'ref-2', 'tenant-1'));

        static::assertSame(DeclarationMode::Real, $real->mode());
        static::assertSame(DeclarationMode::Test, $test->mode());
    }

    public function testTheCommandKeepsItsParts(): void
    {
        $command = self::command('a', 'ref-1', 'tenant-1');

        static::assertSame('tenant-1', $command->tenantId->value());
        static::assertSame('ref-1', $command->externalReference->value());
        static::assertSame(DeclarationMode::Test, $command->flow->mode());
    }
}
