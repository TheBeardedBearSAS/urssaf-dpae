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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;

final class DepositStateTest extends TestCase
{
    #[DataProvider('states')]
    public function testOnlyFailedRejectedAndNotReceivedCloseADeposit(DepositState $state, string $value, bool $open): void
    {
        static::assertSame($value, $state->value);
        static::assertSame($open, $state->isOpen());
    }

    /**
     * @return iterable<string, array{DepositState, string, bool}>
     */
    public static function states(): iterable
    {
        yield 'pending' => [DepositState::Pending, 'pending', true];
        yield 'depositing' => [DepositState::Depositing, 'depositing', true];
        yield 'received' => [DepositState::Received, 'received', true];
        yield 'rejected' => [DepositState::Rejected, 'rejected', false];
        yield 'failed' => [DepositState::Failed, 'failed', false];
        yield 'outcome unknown' => [DepositState::OutcomeUnknown, 'outcome_unknown', true];
        yield 'not received' => [DepositState::NotReceived, 'not_received', false];
        yield 'invalid credentials' => [DepositState::InvalidCredentials, 'invalid_credentials', true];
    }
}
