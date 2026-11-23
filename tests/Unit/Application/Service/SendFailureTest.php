<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Application\Service\SendFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use Throwable;

final class SendFailureTest extends TestCase
{
    #[DataProvider('failures')]
    public function testItClassifiesWhatAFailedSendProves(Throwable $failure, SendFailure $expected): void
    {
        static::assertSame($expected, SendFailure::of($failure));
    }

    /**
     * @return iterable<string, array{Throwable, SendFailure}>
     */
    public static function failures(): iterable
    {
        yield 'unknown outcome' => [OutcomeUnknown::afterStatus(Operation::Deposit, 503), SendFailure::OutcomeUnknown];
        yield 'transport error after the send' => [UrssafUnavailable::dueToTransportError(Operation::Deposit, new RuntimeException('x')), SendFailure::OutcomeUnknown];
        yield 'unavailable before the send' => [UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, null, null), SendFailure::NotSent];
        yield 'credentials refused' => [UrssafAuthenticationFailed::because(AuthenticationFailure::InvalidCredentials, 'realm'), SendFailure::InvalidCredentials];
        yield 'token refused' => [UrssafAuthenticationFailed::because(AuthenticationFailure::TokenInvalid, 'realm'), SendFailure::NotSent];
        yield 'throttled' => [PollingConstraintViolated::withStatus(429, null), SendFailure::NotSent];
        yield 'flow too large' => [InvalidValue::tooLarge('flow'), SendFailure::NotSent];
        yield 'mode not allowed' => [InvariantViolation::because('declaration_mode.not_allowed', 'x'), SendFailure::NotSent];
        yield 'other domain failure after the send' => [InvalidValue::tooLong('flow', 10), SendFailure::OutcomeUnknown];
        yield 'invariant broken after the send' => [InvariantViolation::because('deposit.anything', 'x'), SendFailure::OutcomeUnknown];
        yield 'bad request' => [UrssafRequestRejected::because(Operation::Deposit, 400, 'deposit.bad_request'), SendFailure::NotProcessed];
        yield 'unexpected status' => [UnexpectedUrssafResponse::unexpectedStatus(Operation::Deposit, 406), SendFailure::NotProcessed];
        yield 'anything else' => [new Exception('bug'), SendFailure::Unclassified];
    }
}
