<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Messenger;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\MessageFailures;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\RetryDelay;
use Throwable;

final class MessageFailuresTest extends TestCase
{
    public function testTheResultOfTheOperationIsReturned(): void
    {
        static::assertSame('done', MessageFailures::guard(static fn(): string => 'done'));
    }

    public function testAPollingRefusalIsRecoverableWithItsDelay(): void
    {
        $refusal = PollingConstraintViolated::withStatus(429, 3);

        try {
            MessageFailures::guard(new FailingOperation($refusal));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(3000, $failure);
            static::assertSame($refusal, $failure->getPrevious());
            static::assertSame($refusal->getMessage(), $failure->getMessage());
            static::assertSame(0, $failure->getCode());
        }
    }

    public function testATemporaryFailureWithoutDelayIsRecoverableWithoutDelay(): void
    {
        try {
            MessageFailures::guard(new FailingOperation(UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, null, null)));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $failure) {
            RetryDelay::assertCarries(null, $failure);
        }
    }

    public function testTheDelayIsFoundInTheCauseOfTheFailure(): void
    {
        $cause = UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, 9, null);
        $failure = new class ('wrapped', $cause) extends RuntimeException implements RetryableException {
            public function __construct(string $message, Throwable $previous)
            {
                parent::__construct($message, 0, $previous);
            }
        };

        try {
            MessageFailures::guard(new FailingOperation($failure));
            static::fail('A recoverable failure was expected.');
        } catch (RecoverableMessageHandlingException $recoverable) {
            RetryDelay::assertCarries(9000, $recoverable);
        }
    }

    public function testAPermanentPackageFailureIsUnrecoverable(): void
    {
        $failure = InvalidValue::empty('tenant_id');

        try {
            MessageFailures::guard(new FailingOperation($failure));
            static::fail('An unrecoverable failure was expected.');
        } catch (UnrecoverableMessageHandlingException $unrecoverable) {
            static::assertSame($failure, $unrecoverable->getPrevious());
            static::assertSame($failure->getMessage(), $unrecoverable->getMessage());
            static::assertSame(0, $unrecoverable->getCode());
        }
    }

    public function testAFailureOfAnotherOriginIsLeftToTheHost(): void
    {
        $this->expectException(LogicException::class);

        MessageFailures::guard(new FailingOperation(new LogicException('database down')));
    }

    public function testABusyOperationIsRecoverableWithAFixedDelay(): void
    {
        $failure = MessageFailures::busy('urssaf_dpae.op.scope');

        static::assertSame('Operation already running (lock "urssaf_dpae.op.scope" is held).', $failure->getMessage());
        RetryDelay::assertCarries(30_000, $failure);
        static::assertSame(0, $failure->getCode());
        static::assertNull($failure->getPrevious());
    }

    public function testARefusedPollingIsRecoverableAfterTheAdvisedDelay(): void
    {
        $failure = MessageFailures::refused('returns', 12);

        static::assertSame('URSSAF refused the polling of "returns".', $failure->getMessage());
        RetryDelay::assertCarries(12_000, $failure);
        static::assertSame(0, $failure->getCode());
    }
}
