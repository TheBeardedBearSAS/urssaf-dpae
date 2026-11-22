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

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositOutcomeUnknown as PublicOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use Throwable;

final class DepositDpaeHandlerUnknownOutcomeTest extends TestCase
{
    use DepositHandlerFixtures;

    public function testALostAnswerIsAnUnknownOutcomeAndTheFlowIsNeverResent(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(OutcomeUnknown::afterStatus(Operation::Deposit, 503));
        $handler = $harness->depositHandler(DeclarationMode::Test);
        $command = self::command('a', 'ref-1', 'tenant-1');

        $deposit = $handler($command);
        $again = $handler($command);

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertSame('deposit.outcome_unknown', $deposit->lastErrorCode());
        static::assertSame($deposit, $again);
        static::assertCount(1, $harness->gateway->depositedFor());
        static::assertInstanceOf(DepositOutcomeUnknown::class, $harness->events->events()[0]);
        $published = $harness->outbox->events()[0];
        static::assertInstanceOf(PublicOutcomeUnknown::class, $published);
        static::assertSame('deposit.outcome_unknown', $published->errorCode);
    }

    public function testATransportErrorAfterTheSendIsAnUnknownOutcomeToo(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(UrssafUnavailable::dueToTransportError(Operation::Deposit, new RuntimeException('x')));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
    }

    public function testAnyOtherDomainFailureAfterTheSendIsAnUnknownOutcomeAndNeverResent(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(InvariantViolation::because('reader.broken', 'x'));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertSame('deposit.outcome_unknown', $deposit->lastErrorCode());
        static::assertCount(1, $harness->gateway->depositedFor());
    }

    public function testAnythingElseOnceTheSendStartedIsAnUnknownOutcomeAndIsRethrown(): void
    {
        $harness = self::harness();
        $bug = new Exception('bug');
        $harness->gateway->willDeposit($bug);

        try {
            $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));
            static::fail('Exception expected.');
        } catch (Throwable $e) {
            static::assertSame($bug, $e);
        }

        static::assertSame(DepositState::OutcomeUnknown, $harness->deposits->get(DepositId::fromString('id-1'))->state());
    }
}
