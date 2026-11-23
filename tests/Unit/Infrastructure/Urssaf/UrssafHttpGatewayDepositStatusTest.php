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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\SteppingClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayDepositStatusTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    public function testABadRequestIsARefusalNotAnUnknownOutcome(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(400, '<html>400</html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(Operation::Deposit, $exception->operation());
        static::assertSame(400, $exception->httpStatus());
        static::assertSame('deposit.bad_request', $exception->reason());
    }

    #[DataProvider('providePollingStatuses')]
    public function testA429IsAPollingConstraintWithItsDelay(string $retryAfter, ?int $seconds): void
    {
        $stack = HttpStack::withClock(new SteppingClock(0), ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(429, '', $retryAfter === '' ? [] : ['Retry-After' => $retryAfter]);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(PollingConstraintViolated::class, $exception);
        static::assertSame(429, $exception->status());
        static::assertSame($seconds, $exception->retryAfterSeconds());
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function providePollingStatuses(): iterable
    {
        yield 'seconds' => ['30', 30];
        yield 'http date' => ['Sun, 06 Nov 1994 08:50:37 GMT', 60];
        yield 'none' => ['', null];
    }

    #[DataProvider('provideServerErrors')]
    public function testAServerErrorLeavesTheOutcomeUnknown(int $status): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue($status, '<html>error</html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(\sprintf('URSSAF operation deposit answered HTTP %d: the flow may have been received.', $status), $exception->getMessage());
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertCount(2, $stack->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideServerErrors(): iterable
    {
        yield '500' => [500];
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
        yield '599' => [599];
    }

    #[DataProvider('provideNotProcessedStatuses')]
    public function testAnUndescribedStatusIsUnexpectedAndNotAnUnknownOutcome(int $status): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue($status, '<html/>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(\sprintf('Unexpected URSSAF response: operation deposit answered HTTP %d.', $status), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNotProcessedStatuses(): iterable
    {
        yield '100' => [100];
        yield '301' => [301];
        yield '302' => [302];
        yield '399' => [399];
        yield '403' => [403];
        yield '404' => [404];
        yield '406' => [406];
        yield '415' => [415];
        yield '499' => [499];
    }
}
