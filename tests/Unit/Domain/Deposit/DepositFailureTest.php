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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DepositFailureTest extends TestCase
{
    use DepositFixtures;

    public function testANotSentDepositGoesBackToPendingUntilTheAttemptLimit(): void
    {
        $deposit = self::depositing();

        $deposit->markNotSent('deposit.not_sent', 2, Samples::at(self::T2));

        static::assertSame(DepositState::Pending, $deposit->state());
        static::assertSame('deposit.not_sent', $deposit->lastErrorCode());
        static::assertSame(self::T2, $deposit->updatedAt()->toIso8601());

        $deposit->startDeposit(Samples::at(self::T2), 3);
        $deposit->markNotSent('deposit.not_sent', 2, Samples::at(self::T2));

        static::assertSame(2, $deposit->attempts());
        static::assertSame(DepositState::Failed, $deposit->state());
        static::assertFalse($deposit->isOpen());
    }

    public function testTheAttemptLimitIsReachedExactlyAtTheMaximum(): void
    {
        $deposit = self::depositing();

        $deposit->markNotSent('deposit.not_sent', 1, Samples::at(self::T2));

        static::assertSame(DepositState::Failed, $deposit->state());
    }

    public function testTheMaximumNumberOfAttemptsMustBePositive(): void
    {
        try {
            self::depositing()->markNotSent('deposit.not_sent', 0, Samples::at(self::T2));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('deposit.invalid_max_attempts', $e->violationCode());
            static::assertSame(['max_attempts' => 0], $e->params());
        }
    }

    public function testAnUnprocessedRequestClosesTheDeposit(): void
    {
        $deposit = self::depositing();

        $deposit->markFailed('deposit.not_processed', Samples::at(self::T2));

        static::assertSame(DepositState::Failed, $deposit->state());
        static::assertSame('deposit.not_processed', $deposit->lastErrorCode());
    }

    public function testRefusedCredentialsParkTheDepositUntilItIsRequeued(): void
    {
        $deposit = self::depositing();

        $deposit->markInvalidCredentials(Samples::at(self::T2));

        static::assertSame(DepositState::InvalidCredentials, $deposit->state());
        static::assertSame('deposit.invalid_credentials', $deposit->lastErrorCode());

        $deposit->requeue(Samples::at(self::T2));

        static::assertSame(DepositState::Pending, $deposit->state());
    }
}
