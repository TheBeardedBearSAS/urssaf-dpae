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

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class UrssafHttpGatewayListReturnsPathTest extends TestCase
{
    private const LIST = 'https://consultation.dpae-edi.urssaf.fr/lister-retours-declarant/1.0/';

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

    public function testTheOneDatePathIsUsedWithoutAnEnd(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('retours_children.xml'), ['Content-Type' => 'application/xml']);

        $returns = self::list($stack, null);

        $requests = $stack->requests();
        static::assertCount(2, $requests);
        static::assertSame('GET', $requests[1]->getMethod());
        static::assertSame(self::LIST . '20220130083000', (string) $requests[1]->getUri());
        static::assertSame('DPAELogin jeton=' . HttpStack::JETON, $requests[1]->getHeaderLine('Authorization'));
        static::assertSame('gzip', $requests[1]->getHeaderLine('Accept-Encoding'));
        static::assertCount(3, $returns);
        static::assertSame('FICTIF0000000000000001', $returns[0]->flowId()->value());
        static::assertSame(10, $returns[0]->natureCode());
        static::assertSame('FICTIF0000000000000002', $returns[2]->flowId()->value());
    }

    public function testTheTwoDatesPathIsUsedWithAnEnd(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('retours_children.xml'), ['Content-Type' => 'application/xml']);

        self::list($stack, Instant::fromIso8601('2022-02-01T23:59:59Z'));

        static::assertSame(self::LIST . '20220130083000/20220202005959', (string) $stack->requests()[1]->getUri());
    }

    public function testAnEmptyRangeIsAccepted(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        static::assertSame([], self::list($stack, self::from()));
        static::assertSame(self::LIST . '20220130083000/20220130083000', (string) $stack->requests()[1]->getUri());
    }

    public function testTheDatesAreWrittenInTheConfiguredTimeZone(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withReturnsTimeZone(new DateTimeZone('UTC')), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        self::list($stack, Instant::fromIso8601('2022-07-01T10:00:00+02:00'));

        static::assertSame(self::LIST . '20220130073000/20220701080000', (string) $stack->requests()[1]->getUri());
    }

    public function testTheReturnsAreReadInTheConfiguredTimeZone(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withReturnsTimeZone(new DateTimeZone('UTC')), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('retours_children.xml'), ['Content-Type' => 'application/xml']);

        $returns = self::list($stack, null);

        static::assertSame('2022-01-30T08:30:00Z', $returns[0]->publishedAt()?->toIso8601());
    }
}
