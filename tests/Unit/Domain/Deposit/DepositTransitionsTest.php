<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Deposit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DepositTransitionsTest extends TestCase
{
    use DepositFixtures;

    #[DataProvider('forbiddenTransitions')]
    public function testEveryTransitionRefusesTheOtherStates(DepositState $from, string $transition): void
    {
        $deposit = self::deposit();
        self::moveTo($deposit, $from);

        try {
            self::attempt($deposit, $transition);
            static::fail('InvalidStateTransition expected.');
        } catch (InvalidStateTransition $e) {
            static::assertSame('deposit.invalid_state_transition', $e->violationCode());
            static::assertSame($from->value, $e->from());
        }
    }

    /**
     * @return iterable<string, array{DepositState, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'claim a depositing one' => [DepositState::Depositing, 'claim'];
        yield 'claim a received one' => [DepositState::Received, 'claim'];
        yield 'not sent from pending' => [DepositState::Pending, 'not_sent'];
        yield 'failed from pending' => [DepositState::Pending, 'failed'];
        yield 'credentials from pending' => [DepositState::Pending, 'credentials'];
        yield 'unknown from pending' => [DepositState::Pending, 'unknown'];
        yield 'requeue a pending one' => [DepositState::Pending, 'requeue'];
        yield 'requeue an unknown one' => [DepositState::OutcomeUnknown, 'requeue'];
        yield 'received from pending' => [DepositState::Pending, 'resolve_received'];
        yield 'rejected from received' => [DepositState::Received, 'resolve_rejected'];
        yield 'not received from received' => [DepositState::Received, 'resolve_not_received'];
    }

    /**
     * @throws InvalidStateTransition
     */
    private static function attempt(Deposit $deposit, string $transition): void
    {
        $now = Samples::at(self::T2);
        match ($transition) {
            'claim' => $deposit->startDeposit($now, 3),
            'not_sent' => $deposit->markNotSent('x', 3, $now),
            'failed' => $deposit->markFailed('x', $now),
            'credentials' => $deposit->markInvalidCredentials($now),
            'unknown' => $deposit->markOutcomeUnknown('x', $now),
            'requeue' => $deposit->requeue($now),
            'resolve_received' => $deposit->resolveAsReceived(Samples::flowId('F'), $now),
            'resolve_rejected' => $deposit->resolveAsRejected(Samples::flowId('F'), $now),
            'resolve_not_received' => $deposit->resolveAsNotReceived($now),
            default => static::fail('Unknown transition.'),
        };
    }

    private static function moveTo(Deposit $deposit, DepositState $state): void
    {
        if ($state === DepositState::Pending) {
            return;
        }
        $now = Samples::at(self::T1);
        $deposit->startDeposit($now, 3);
        if ($state === DepositState::OutcomeUnknown) {
            $deposit->markOutcomeUnknown('x', $now);
        }
        if ($state === DepositState::Received) {
            $deposit->recordResult(SampleAnswers::receipt('F1'), $now);
        }
    }
}
