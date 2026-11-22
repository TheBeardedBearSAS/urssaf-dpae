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
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayListReturnsAnswerTest extends TestCase
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

    public function testAnHtmlPageWithA200IsUnexpected(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<html><body>maintenance</body></html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): array => self::list($stack, null));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the answer is an HTML page.', $exception->getMessage());
    }

    public function testAnUnreadableBodyIsUnexpected(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<retours><flux>', ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(UnexpectedUrssafResponse::class, Thrown::by(static fn(): array => self::list($stack, null)));
    }

    public function testARefusedCredentialStopsTheListing(): void
    {
        $stack = self::stack();
        $stack->queue(401, '', ['WWW-Authenticate' => 'DPAELogin realm="Utilisateur non-inscrit au service"']);

        $exception = Thrown::by(static fn(): array => self::list($stack, null));

        static::assertInstanceOf(UrssafAuthenticationFailed::class, $exception);
        static::assertTrue($exception->isCredentialRefusal());
    }

    public function testTheTokenIsReusedAcrossCalls(): void
    {
        $stack = self::stack();
        $stack->tokens->save(
            'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'),
            DpaeToken::fromResponseBody(HttpStack::JETON),
        );
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        self::list($stack, null);

        static::assertCount(1, $stack->requests());
    }
}
