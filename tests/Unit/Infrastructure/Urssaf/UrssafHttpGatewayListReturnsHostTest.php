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
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayListReturnsHostTest extends TestCase
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

    public function testAConcentratorListsOnTheConsultationHostWithThePort(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        self::list($stack, null);

        static::assertSame(
            'https://consultation.dpae-edi.urssaf.fr:8443/lister-retours-declarant/1.0/20220130083000',
            (string) $stack->requests()[1]->getUri(),
        );
    }

    public function testTheConsultationHostIsOverridable(): void
    {
        $endpoints = ApiConfiguration::defaults()->endpoints()->withBaseUri(DpaeService::Consultation, 'http://mock.test:1080');
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withEndpoints($endpoints), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        self::list($stack, null);

        static::assertSame('http://mock.test:1080/lister-retours-declarant/1.0/20220130083000', (string) $stack->requests()[1]->getUri());
    }

    public function testARangeEndingBeforeItsStartIsRefusedBeforeAnyCall(): void
    {
        $stack = self::stack();

        $exception = Thrown::by(static fn(): array => self::list($stack, Instant::fromIso8601('2022-01-30T07:29:59Z')));

        static::assertInstanceOf(InvariantViolation::class, $exception);
        static::assertSame('returns.range_inverted', $exception->violationCode());
        static::assertSame([], $stack->requests());
    }

    public function testAnAnswerInGzipAndLatin1IsRead(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><retours><flux><id>F\xE9</id><retour><publication>20220130083000</publication><production>20220130082500</production><nature>10</nature><statut>OK</statut></retour></flux></retours>";
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, (string) gzencode($xml), ['Content-Type' => 'application/xml', 'Content-Encoding' => 'gzip']);

        $returns = self::list($stack, null);

        static::assertCount(1, $returns);
        static::assertSame('Fé', $returns[0]->flowId()->value());
    }
}
