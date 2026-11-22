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
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use Throwable;

final class UrssafUnavailableTest extends TestCase
{
    public function testAnAnsweredStatusMayHaveReachedUrssaf(): void
    {
        $exception = UrssafUnavailable::withStatus(Operation::ListReturns, 503, 30);

        static::assertTrue(self::is(RetryableException::class, $exception));
        static::assertSame('URSSAF operation list_returns is temporarily unavailable (HTTP 503).', $exception->getMessage());
        static::assertSame(Operation::ListReturns, $exception->operation());
        static::assertSame(503, $exception->httpStatus());
        static::assertSame(503, $exception->getCode());
        static::assertSame(30, $exception->retryAfterSeconds());
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertNull($exception->causeClass());
    }

    public function testATransportErrorMayHaveReachedUrssafAndKeepsOnlyTheClassOfItsCause(): void
    {
        $cause = new RuntimeException('connect to https://secret.example failed');
        $exception = UrssafUnavailable::dueToTransportError(Operation::Deposit, $cause);

        static::assertSame('URSSAF operation deposit could not be reached.', $exception->getMessage());
        static::assertSame(Operation::Deposit, $exception->operation());
        static::assertNull($exception->httpStatus());
        static::assertSame(0, $exception->getCode());
        static::assertNull($exception->retryAfterSeconds());
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertSame(RuntimeException::class, $exception->causeClass());
        static::assertStringNotContainsString('secret.example', (string) $exception);
    }

    public function testAFailedTokenCallProvesTheCallWasNotSent(): void
    {
        $exception = UrssafUnavailable::whileAuthenticating(Operation::Deposit, 429, 12, null);

        static::assertSame('URSSAF authentication for operation deposit is temporarily unavailable.', $exception->getMessage());
        static::assertSame(Operation::Deposit, $exception->operation());
        static::assertSame(429, $exception->httpStatus());
        static::assertSame(12, $exception->retryAfterSeconds());
        static::assertFalse($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertNull($exception->causeClass());
        static::assertSame(RuntimeException::class, UrssafUnavailable::whileAuthenticating(Operation::Deposit, null, null, new RuntimeException('x'))->causeClass());
    }

    /**
     * @param class-string $type
     */
    private static function is(string $type, Throwable $exception): bool
    {
        return $exception instanceof $type;
    }
}
