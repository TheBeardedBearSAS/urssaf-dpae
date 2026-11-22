<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf;

use Http\Client\Exception\NetworkException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayDepositAuthenticationTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    public function testAnExpiredTokenIsRenewedAndTheDepositReplayedOnce(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '<html/>', ['WWW-Authenticate' => 'DPAELogin realm="Expiration du jeton"']);
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, self::deposit($stack));
        static::assertCount(4, $stack->requests());
    }

    public function testAFailedTokenCallProvesNothingWasSent(): void
    {
        $stack = self::stack();
        $stack->queue(503, '<html/>', ['Retry-After' => '9']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertFalse($exception->mayHaveReachedUrssaf());
        static::assertSame(9, $exception->retryAfterSeconds());
        static::assertSame(Operation::Deposit, $exception->operation());
        static::assertCount(1, $stack->requests());
    }

    public function testATransportErrorOnTheTokenCallProvesNothingWasSent(): void
    {
        $stack = self::stack();
        $stack->mock()->addException(new NetworkException('down', $stack->factory->createRequest('POST', 'https://mon.urssaf.fr/authentifier_dpae')));

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertFalse($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->httpStatus());
    }

    public function testARefusedCredentialIsNotAnUnknownOutcome(): void
    {
        $stack = self::stack();
        $stack->queue(401, '', ['WWW-Authenticate' => 'Basic realm="Expiration du mot de passe"']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(AuthenticationFailure::PasswordExpired, $exception->failure());
        static::assertTrue($exception->isCredentialRefusal());
    }

    public function testASecondExpiredAnswerIsAnAuthenticationFailureNotAnUnknownOutcome(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => 'DPAELogin realm="Expiration du jeton"']);
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => 'DPAELogin realm="Expiration du jeton"']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(AuthenticationFailure::TokenExpired, $exception->failure());
    }
}
