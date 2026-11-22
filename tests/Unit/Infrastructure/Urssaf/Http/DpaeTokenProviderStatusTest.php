<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

final class DpaeTokenProviderStatusTest extends TestCase
{
    private const KEY = 'urssaf_dpae.token.';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    #[DataProvider('provideRealms')]
    public function testA401IsMappedByItsRealm(string $challenge, AuthenticationFailure $failure, bool $credentialRefusal): void
    {
        $stack = self::stack();
        $stack->queue(401, '<html>401 Unauthorized</html>', ['WWW-Authenticate' => $challenge, 'Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame($failure, $exception->failure());
        static::assertSame($credentialRefusal, $exception->isCredentialRefusal());
        static::assertNull($stack->tokens->get(self::KEY . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25')));
    }

    /**
     * @return iterable<string, array{string, AuthenticationFailure, bool}>
     */
    public static function provideRealms(): iterable
    {
        yield 'password expired' => ["Basic realm=\xC2\xABExpiration du mot de passe\xC2\xBB", AuthenticationFailure::PasswordExpired, true];
        yield 'wrong account' => ["Basic realm=\xC2\xABnet-entreprises.fr\xC2\xBB", AuthenticationFailure::InvalidCredentials, true];
        yield 'not registered' => ["DPAELogin realm=\xC2\xABUtilisateur non-inscrit au service\xC2\xBB", AuthenticationFailure::NotRegistered, true];
        yield 'token realm on the token call' => ['DPAELogin realm="Jeton manquant ou invalide"', AuthenticationFailure::TokenInvalid, false];
        yield 'unknown realm' => ['Basic realm="x"', AuthenticationFailure::Unknown, false];
        yield 'no challenge' => ['', AuthenticationFailure::Unknown, false];
    }

    public function testA422IsARefusedRequest(): void
    {
        $stack = self::stack();
        $stack->queue(422, '<html>422</html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(Operation::Authenticate, $exception->operation());
        static::assertSame(422, $exception->httpStatus());
        static::assertSame('authentication.invalid_parameters', $exception->reason());
    }

    #[DataProvider('provideTransientStatuses')]
    public function testATransientStatusIsUnavailableAndTheCallIsNotSent(int $status): void
    {
        $stack = self::stack();
        $stack->queue($status, '<html/>', ['Retry-After' => '7']);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::ListReturns));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame(Operation::ListReturns, $exception->operation());
        static::assertSame($status, $exception->httpStatus());
        static::assertSame(7, $exception->retryAfterSeconds());
        static::assertFalse($exception->mayHaveReachedUrssaf());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTransientStatuses(): iterable
    {
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
        yield '599' => [599];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testAnyOtherStatusIsUnexpected(int $status): void
    {
        $stack = self::stack();
        $stack->queue($status, '<html/>', []);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(\sprintf('Unexpected URSSAF response: operation authenticate answered HTTP %d.', $status), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield '100' => [100];
        yield '302' => [302];
        yield '400' => [400];
        yield '403' => [403];
        yield '404' => [404];
        yield '406' => [406];
        yield '415' => [415];
        yield '428' => [428];
    }
}
