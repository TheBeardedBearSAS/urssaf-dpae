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

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositFailed as PublicFailed;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositParked as PublicParked;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;

final class DepositDpaeHandlerNotSentTest extends TestCase
{
    use DepositHandlerFixtures;

    public function testAFlowThatDidNotReachUrssafIsRetriedWithTheSameDepositUntilTheAttemptLimit(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $unavailable = UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, null, null);
        $harness->gateway->willDeposit($unavailable);
        $harness->gateway->willDeposit($unavailable);
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $handler = $harness->depositHandler(DeclarationMode::Test);
        $command = self::command('a', 'ref-1', 'tenant-1');

        foreach ([1, 2] as $attempt) {
            try {
                $handler($command);
                static::fail('UrssafUnavailable expected.');
            } catch (UrssafUnavailable $e) {
                static::assertSame($unavailable, $e);
            }
            $deposit = $harness->deposits->get(DepositId::fromString('id-1'));
            static::assertSame(DepositState::Pending, $deposit->state());
            static::assertSame($attempt, $deposit->attempts());
            static::assertSame('deposit.not_sent', $deposit->lastErrorCode());
        }

        $final = $handler($command);

        static::assertSame('id-1', $final->id()->value());
        static::assertSame(DepositState::Received, $final->state());
        static::assertSame(3, $final->attempts());
    }

    public function testTheDepositFailsOnceTheAttemptLimitIsReached(): void
    {
        $harness = Harness::inMemory(new WorkflowPolicy(
            2,
            new DateInterval('PT15M'),
            new DateInterval('PT5M'),
            new DateInterval('PT1H'),
            new DateInterval('PT6H'),
            new DateInterval('PT5M'),
            new DateInterval('P7D'),
            null,
            60,
            5,
            null,
        ));
        $throttled = PollingConstraintViolated::withStatus(429, null);
        $harness->gateway->willDeposit($throttled);
        $harness->gateway->willDeposit($throttled);
        $handler = $harness->depositHandler(DeclarationMode::Test);
        $command = self::command('a', 'ref-1', 'tenant-1');

        foreach ([DepositState::Pending, DepositState::Failed] as $expected) {
            try {
                $handler($command);
                static::fail('PollingConstraintViolated expected.');
            } catch (PollingConstraintViolated) {
                static::assertSame($expected, $harness->deposits->get(DepositId::fromString('id-1'))->state());
            }
        }
    }

    public function testATokenRefusedAfterTheReplayIsNotAnUnknownOutcome(): void
    {
        $harness = self::harness();
        $failure = UrssafAuthenticationFailed::because(AuthenticationFailure::TokenInvalid, 'realm');
        $harness->gateway->willDeposit($failure);

        try {
            $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));
            static::fail('UrssafAuthenticationFailed expected.');
        } catch (UrssafAuthenticationFailed $e) {
            static::assertSame($failure, $e);
        }

        static::assertSame(DepositState::Pending, $harness->deposits->get(DepositId::fromString('id-1'))->state());
    }

    public function testALocalRefusalBeforeTheSendLeavesTheDepositPendingAndIsRethrown(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(InvalidValue::tooLarge('flow'));

        try {
            $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));
            static::fail('InvalidValue expected.');
        } catch (InvalidValue) {
            static::assertSame(DepositState::Pending, $harness->deposits->get(DepositId::fromString('id-1'))->state());
        }
    }

    public function testRefusedCredentialsParkTheDeposit(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(UrssafAuthenticationFailed::because(AuthenticationFailure::InvalidCredentials, 'realm'));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::InvalidCredentials, $deposit->state());
        static::assertSame('deposit.invalid_credentials', $deposit->lastErrorCode());
    }

    public function testParkingAndClosingADepositAreAnnounced(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(UrssafAuthenticationFailed::because(AuthenticationFailure::InvalidCredentials, 'realm'));
        $harness->gateway->willDeposit(UrssafRequestRejected::because(Operation::Deposit, 400, 'deposit.bad_request'));
        $handler = $harness->depositHandler(DeclarationMode::Test);

        $handler(self::command('a', 'ref-1', 'tenant-1'));
        $handler(self::command('b', 'ref-2', 'tenant-1'));

        $events = $harness->outbox->events();
        static::assertCount(2, $events);
        static::assertInstanceOf(PublicParked::class, $events[0]);
        static::assertSame('deposit.invalid_credentials', $events[0]->errorCode);
        static::assertInstanceOf(PublicFailed::class, $events[1]);
        static::assertSame('deposit.not_processed', $events[1]->errorCode);
    }

    public function testARequestRefusedWithoutProcessingClosesTheDeposit(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(UrssafRequestRejected::because(Operation::Deposit, 400, 'deposit.bad_request'));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::Failed, $deposit->state());
        static::assertSame('deposit.not_processed', $deposit->lastErrorCode());
    }

    public function testAnUnexpectedStatusClosesTheDepositToo(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(UnexpectedUrssafResponse::unexpectedStatus(Operation::Deposit, 406));

        $deposit = $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::Failed, $deposit->state());
    }
}
