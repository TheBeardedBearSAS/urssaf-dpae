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

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;

final class HttpAnswerRealmTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function answer(int $status, string $body, array $headers, ?int $max): HttpAnswer
    {
        return HttpAnswer::fromResponse(new Response($status, $headers, $body), $max);
    }

    #[DataProvider('provideRealms')]
    public function testTheRealmOfTheChallengeTellsTheFailure(string $header, AuthenticationFailure $expected): void
    {
        static::assertSame($expected, self::answer(401, '', ['WWW-Authenticate' => $header], null)->authenticationFailure());
    }

    /**
     * @return iterable<string, array{string, AuthenticationFailure}>
     */
    public static function provideRealms(): iterable
    {
        yield 'password expired, guillemets' => ["Basic realm=\xC2\xABExpiration du mot de passe\xC2\xBB", AuthenticationFailure::PasswordExpired];
        yield 'invalid credentials, guillemets' => ["Basic realm=\xC2\xABnet-entreprises.fr\xC2\xBB", AuthenticationFailure::InvalidCredentials];
        yield 'not registered, guillemets' => ["DPAELogin realm=\xC2\xABUtilisateur non-inscrit au service\xC2\xBB", AuthenticationFailure::NotRegistered];
        yield 'token invalid' => ["DPAELogin realm=\xC2\xABJeton manquant ou invalide\xC2\xBB", AuthenticationFailure::TokenInvalid];
        yield 'token expired' => ["DPAELogin realm=\xC2\xABExpiration du jeton\xC2\xBB", AuthenticationFailure::TokenExpired];
        yield 'upper-case parameter name' => ['DPAELogin Realm="Expiration du jeton"', AuthenticationFailure::TokenExpired];
        yield 'quotes' => ['DPAELogin realm="Expiration du jeton"', AuthenticationFailure::TokenExpired];
        yield 'latin-1 guillemets' => ["DPAELogin realm=\xABExpiration du jeton\xBB", AuthenticationFailure::TokenExpired];
        yield 'spaces around the equal sign' => ['DPAELogin realm = "Expiration du jeton"', AuthenticationFailure::TokenExpired];
        yield 'spaces inside the quotes' => ['DPAELogin realm=" Expiration du jeton "', AuthenticationFailure::TokenExpired];
        yield 'several challenges' => ['Basic realm="net-entreprises.fr", DPAELogin realm="Expiration du jeton"', AuthenticationFailure::InvalidCredentials];
        yield 'unknown realm' => ['DPAELogin realm="Autre"', AuthenticationFailure::Unknown];
        yield 'empty realm' => ['DPAELogin realm=""', AuthenticationFailure::Unknown];
        yield 'no realm' => ['DPAELogin', AuthenticationFailure::Unknown];
        yield 'no header' => ['', AuthenticationFailure::Unknown];
        yield 'unbalanced quote' => ['DPAELogin realm="Expiration du jeton', AuthenticationFailure::Unknown];
    }

    public function testTheRawRealmIsKeptTrimmedAndShort(): void
    {
        static::assertSame('Autre', self::answer(401, '', ['WWW-Authenticate' => 'DPAELogin realm=" Autre "'], null)->realm());
        static::assertSame('Expiration du jeton', self::answer(401, '', ['WWW-Authenticate' => 'DPAELogin realm="Expiration du jeton"'], null)->realm());
        static::assertSame(str_repeat('a', 100), self::answer(401, '', ['WWW-Authenticate' => 'DPAELogin realm="' . str_repeat('a', 101) . '"'], null)->realm());
        static::assertSame('', self::answer(401, '', ['WWW-Authenticate' => 'DPAELogin realm=""'], null)->realm());
        static::assertNull(self::answer(401, '', ['WWW-Authenticate' => 'DPAELogin'], null)->realm());
    }
}
