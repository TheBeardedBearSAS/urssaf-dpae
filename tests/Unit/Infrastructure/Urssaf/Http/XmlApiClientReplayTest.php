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
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;

final class XmlApiClientReplayTest extends TestCase
{
    private const URI = 'https://consultation.dpae-edi.urssaf.fr/lister-retours-declarant/1.0/20220130083000';
    private const EXPIRED = 'DPAELogin realm="Expiration du jeton"';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function read(): ApiRequest
    {
        return ApiRequest::get(Operation::ListReturns, self::URI);
    }

    private static function tokenKey(): string
    {
        return 'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25');
    }

    public function testAnExpiredTokenAuthenticatesAgainAndReplaysTheCallOnce(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '<html/>', ['WWW-Authenticate' => self::EXPIRED]);
        $stack->queue(200, 'ok', ['Content-Type' => 'application/octet-stream']);
        $stack->mock()->addResponse($stack->factory->createResponse(200)->withBody($stack->factory->createStream('unused')));

        $answer = Wiring::client($stack)->send($stack->tenant(), $stack->credentials, ApiRequest::postGzip(
            Operation::Deposit,
            'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0',
            '<flow/>',
            'text/plain',
        ));

        static::assertSame(200, $answer->status());
        $requests = $stack->requests();
        static::assertCount(4, $requests);
        static::assertSame(
            ['POST', 'POST', 'POST', 'POST'],
            array_map(static fn(RequestInterface $request): string => $request->getMethod(), $requests),
        );
        static::assertSame('/authentifier_dpae', $requests[0]->getUri()->getPath());
        static::assertSame('/deposer-dsn/1.0', $requests[1]->getUri()->getPath());
        static::assertSame('/authentifier_dpae', $requests[2]->getUri()->getPath());
        static::assertSame('/deposer-dsn/1.0', $requests[3]->getUri()->getPath());
        static::assertSame('<flow/>', gzdecode((string) $requests[1]->getBody()));
        static::assertSame('<flow/>', gzdecode((string) $requests[3]->getBody()));
        static::assertNotNull($stack->tokens->get(self::tokenKey()));
    }

    public function testASecondExpiredAnswerIsThrownAndTheTokenDropped(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => self::EXPIRED]);
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => self::EXPIRED]);

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(AuthenticationFailure::TokenExpired, $exception->failure());
        static::assertCount(4, $stack->requests());
        static::assertNull($stack->tokens->get(self::tokenKey()));
    }

    public function testAnInvalidTokenIsNotReplayedButDropped(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => 'DPAELogin realm="Jeton manquant ou invalide"']);

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(AuthenticationFailure::TokenInvalid, $exception->failure());
        static::assertCount(2, $stack->requests());
        static::assertNull($stack->tokens->get(self::tokenKey()));
    }

    #[DataProvider('provideNonReplayedChallenges')]
    public function testAnyOtherChallengeIsThrownWithoutReplay(string $challenge, AuthenticationFailure $failure): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '', $challenge === '' ? [] : ['WWW-Authenticate' => $challenge]);

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame($failure, $exception->failure());
        static::assertCount(2, $stack->requests());
        static::assertNull($stack->tokens->get(self::tokenKey()));
    }

    /**
     * @return iterable<string, array{string, AuthenticationFailure}>
     */
    public static function provideNonReplayedChallenges(): iterable
    {
        yield 'not registered' => ['DPAELogin realm="Utilisateur non-inscrit au service"', AuthenticationFailure::NotRegistered];
        yield 'password expired' => ['Basic realm="Expiration du mot de passe"', AuthenticationFailure::PasswordExpired];
        yield 'no challenge' => ['', AuthenticationFailure::Unknown];
    }

    public function testAFailedReauthenticationAfterAnExpiryIsThrownAsIs(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(401, '', ['WWW-Authenticate' => self::EXPIRED]);
        $stack->queue(401, '', ['WWW-Authenticate' => 'Basic realm="Expiration du mot de passe"']);

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertSame(AuthenticationFailure::PasswordExpired, $exception->failure());
        static::assertCount(3, $stack->requests());
    }
}
