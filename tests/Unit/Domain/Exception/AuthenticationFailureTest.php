<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;

final class AuthenticationFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, AuthenticationFailure, bool}>
     */
    public static function provideRealms(): iterable
    {
        yield 'password expired' => ['Expiration du mot de passe', AuthenticationFailure::PasswordExpired, true];
        yield 'invalid credentials' => ['net-entreprises.fr', AuthenticationFailure::InvalidCredentials, true];
        yield 'not registered' => ['Utilisateur non-inscrit au service', AuthenticationFailure::NotRegistered, true];
        yield 'token invalid' => ['Jeton manquant ou invalide', AuthenticationFailure::TokenInvalid, false];
        yield 'token expired' => ['Expiration du jeton', AuthenticationFailure::TokenExpired, false];
        yield 'other realm' => ['Autre royaume', AuthenticationFailure::Unknown, false];
        yield 'missing realm' => [null, AuthenticationFailure::Unknown, false];
    }

    #[DataProvider('provideRealms')]
    public function testRealmIsMappedAndClassified(?string $realm, AuthenticationFailure $expected, bool $credentialRefusal): void
    {
        $failure = AuthenticationFailure::fromRealm($realm);

        static::assertSame($expected, $failure);
        static::assertSame($credentialRefusal, $failure->isCredentialRefusal());
    }
}
