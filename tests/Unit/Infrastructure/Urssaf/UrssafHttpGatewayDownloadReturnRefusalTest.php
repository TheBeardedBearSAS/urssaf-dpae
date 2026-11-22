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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\SteppingClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;
use Throwable;

final class UrssafHttpGatewayDownloadReturnRefusalTest extends TestCase
{
    private static function reference(string $flowId, ?string $returnId, ?string $url): ReturnReference
    {
        return ReturnReference::fromListing(
            FlowId::fromString($flowId),
            10,
            'OK',
            '20220130083000',
            '20220130082500',
            null,
            null,
            $returnId,
            null,
            null,
            $url,
        );
    }

    private static function download(HttpStack $stack, ReturnReference $return): ReturnContent
    {
        return $stack->gateway()->downloadReturn($stack->tenant(), $return);
    }

    public function testAReturnOfAnotherDeclarantIsForbidden(): void
    {
        $exception = $this->failWith(403);

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(Operation::DownloadReturn, $exception->operation());
        static::assertSame(403, $exception->httpStatus());
        static::assertSame('return.not_owned', $exception->reason());
    }

    public function testAnUnknownOrPurgedReturnIsNoLongerAvailable(): void
    {
        $exception = $this->failWith(404);

        static::assertInstanceOf(ReturnNoLongerAvailable::class, $exception);
        static::assertTrue(self::reference('F1', '1', null)->identity()->equals($exception->identity()));
    }

    public function testInvalidParametersAreARefusedRequest(): void
    {
        $exception = $this->failWith(422);

        static::assertInstanceOf(UrssafRequestRejected::class, $exception);
        static::assertSame(422, $exception->httpStatus());
        static::assertSame('return.invalid_parameters', $exception->reason());
    }

    public function testA429IsAPollingConstraint(): void
    {
        $exception = $this->failWith(429);

        static::assertInstanceOf(PollingConstraintViolated::class, $exception);
        static::assertSame(429, $exception->status());
        static::assertSame(30, $exception->retryAfterSeconds());
    }

    private function failWith(int $status): Throwable
    {
        $stack = HttpStack::withClock(new SteppingClock(0), ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue($status, '<html/>', ['Content-Type' => 'text/html', 'Retry-After' => '30']);

        return Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', '1', null)));
    }
}
