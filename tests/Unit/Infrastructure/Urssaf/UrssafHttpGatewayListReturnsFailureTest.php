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
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\SteppingClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayListReturnsFailureTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function from(): Instant
    {
        return Instant::fromIso8601('2022-01-30T07:30:00Z');
    }

    /**
     * @return list<ReturnReference>
     */
    private static function list(HttpStack $stack, ?Instant $to): array
    {
        return $stack->gateway()->listReturns($stack->tenant(), self::from(), $to);
    }

    #[DataProvider('providePollingRefusals')]
    public function testA422Or429IsARetryablePollingConstraintThatKeepsTheStatus(int $status, string $retryAfter, ?int $seconds): void
    {
        $stack = HttpStack::withClock(new SteppingClock(0), ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue($status, '<html/>', $retryAfter === '' ? [] : ['Retry-After' => $retryAfter]);

        $exception = Thrown::by(static fn(): array => self::list($stack, null));

        static::assertInstanceOf(PollingConstraintViolated::class, $exception);
        static::assertSame($status, $exception->status());
        static::assertSame($seconds, $exception->retryAfterSeconds());
    }

    /**
     * @return iterable<string, array{int, string, int|null}>
     */
    public static function providePollingRefusals(): iterable
    {
        yield '422 without delay' => [422, '', null];
        yield '422 with seconds' => [422, '45', 45];
        yield '422 with a date' => [422, 'Sun, 06 Nov 1994 08:50:37 GMT', 60];
        yield '429 without delay' => [429, '', null];
        yield '429 with seconds' => [429, '120', 120];
        yield '429 with a date' => [429, 'Sun, 06 Nov 1994 09:49:37 GMT', 3600];
    }

    #[DataProvider('provideServerErrors')]
    public function testAServerErrorIsTransient(int $status): void
    {
        $stack = HttpStack::withClock(new SteppingClock(0), ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue($status, '<html/>', ['Retry-After' => 'Sun, 06 Nov 1994 08:49:47 GMT']);

        $exception = Thrown::by(static fn(): array => self::list($stack, null));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame(Operation::ListReturns, $exception->operation());
        static::assertSame($status, $exception->httpStatus());
        static::assertSame(10, $exception->retryAfterSeconds());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideServerErrors(): iterable
    {
        yield '500' => [500];
        yield '503' => [503];
        yield '599' => [599];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testAnyOtherStatusIsUnexpected(int $status): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue($status, '<html/>', []);

        $exception = Thrown::by(static fn(): array => self::list($stack, null));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(\sprintf('Unexpected URSSAF response: operation list_returns answered HTTP %d.', $status), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield '302' => [302];
        yield '400' => [400];
        yield '403' => [403];
        yield '404' => [404];
        yield '406' => [406];
        yield '415' => [415];
        yield '499' => [499];
    }
}
