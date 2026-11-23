<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Port\Urssaf;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use Throwable;

final class UrssafAuthenticationFailedTest extends TestCase
{
    public function testACredentialRefusalIsReported(): void
    {
        $exception = UrssafAuthenticationFailed::because(AuthenticationFailure::PasswordExpired, null);

        static::assertTrue(self::is(UrssafException::class, $exception));
        static::assertFalse(self::is(RetryableException::class, $exception));
        static::assertSame('URSSAF authentication failed (PasswordExpired).', $exception->getMessage());
        static::assertSame(401, $exception->getCode());
        static::assertSame(AuthenticationFailure::PasswordExpired, $exception->failure());
        static::assertTrue($exception->isCredentialRefusal());
    }

    public function testTheRawRealmIsKeptWhenGiven(): void
    {
        static::assertNull(UrssafAuthenticationFailed::because(AuthenticationFailure::Unknown, null)->realm());
        static::assertSame('Autre', UrssafAuthenticationFailed::because(AuthenticationFailure::Unknown, 'Autre')->realm());
    }

    public function testATokenRefusalIsNotACredentialRefusal(): void
    {
        $exception = UrssafAuthenticationFailed::because(AuthenticationFailure::TokenInvalid, null);

        static::assertSame(AuthenticationFailure::TokenInvalid, $exception->failure());
        static::assertFalse($exception->isCredentialRefusal());
    }

    /**
     * @param class-string $type
     */
    private static function is(string $type, Throwable $exception): bool
    {
        return $exception instanceof $type;
    }
}
