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

use DateTimeImmutable;
use Http\Client\Exception\NetworkException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\AnswerInterpreter;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Wiring;
use Throwable;

/**
 * What a failing deposit may reveal (no secret, no URI, no chained PSR-18 exception), and what it must not hide.
 */
final class UrssafHttpGatewayDepositSecretsTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    private static function assertNoSecret(Throwable $exception): void
    {
        $rendered = '';
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $rendered .= (string) $current;
        }

        static::assertNull($exception->getPrevious());
        foreach ([HttpStack::JETON, HttpStack::MOTDEPASSE, 'DPAELogin', 'Authorization', 'urssaf.fr'] as $secret) {
            static::assertStringNotContainsString($secret, $rendered);
        }
    }

    public function testATransportErrorOnTheDepositRevealsNothing(): void
    {
        $stack = self::stack();
        $stack->tokens->save(
            'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'),
            DpaeToken::fromResponseBody(HttpStack::JETON),
        );
        $request = $stack->factory->createRequest('POST', 'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0')->withHeader('Authorization', 'DPAELogin jeton=' . HttpStack::JETON);
        $stack->mock()->addException(new NetworkException('failed ' . HttpStack::JETON, $request));

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        self::assertNoSecret($exception);
    }

    public function testATokenTransportErrorDuringADepositRevealsNothingAndSendsNothing(): void
    {
        $stack = self::stack();
        $request = $stack->factory->createRequest('POST', 'https://mon.urssaf.fr/authentifier_dpae')->withBody($stack->factory->createStream('<password>' . HttpStack::MOTDEPASSE . '</password>'));
        $stack->mock()->addException(new NetworkException('failed ' . HttpStack::MOTDEPASSE, $request));

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertFalse($exception->mayHaveReachedUrssaf());
        self::assertNoSecret($exception);
        static::assertStringNotContainsString(HttpStack::MOTDEPASSE, $stack->logger->dump());
    }

    public function testAnUnexpectedFailureAfterTheSendLeavesTheOutcomeUnknown(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(429, '', []);
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                throw new RuntimeException('clock broke ' . HttpStack::JETON);
            }
        };
        $gateway = new UrssafHttpGateway(
            new InMemoryCredentialsProvider([HttpStack::TENANT => $stack->credentials]),
            Wiring::client($stack),
            $stack->configuration,
            new AnswerInterpreter($clock),
        );

        $exception = Thrown::by(static fn(): DepositResult => $gateway->deposit($stack->tenant(), Fixtures::flow()));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(
            'URSSAF operation deposit failed with RuntimeException: the flow may have been received.',
            $exception->getMessage(),
        );
        self::assertNoSecret($exception);
        static::assertCount(2, $stack->requests());
    }
}
