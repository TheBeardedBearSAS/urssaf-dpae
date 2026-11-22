<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use Http\Client\Exception\NetworkException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use RuntimeException;
use Stringable;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\XmlApiClient;

/**
 * A failing logger changes nothing once the request was sent.
 */
final class XmlApiClientLoggingTest extends TestCase
{
    private const URI = 'https://consultation.dpae-edi.urssaf.fr/lister-retours-declarant/1.0/20260101000000';

    private static function client(HttpStack $stack): XmlApiClient
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger broke');
            }
        };

        return new XmlApiClient(
            $stack->client,
            $stack->factory,
            $stack->factory,
            Wiring::provider($stack),
            $stack->clock,
            $stack->configuration,
            $logger,
        );
    }

    public function testAFailingLoggerDoesNotHideTheAnswer(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->tokens->save('urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'), DpaeToken::fromResponseBody(HttpStack::JETON));
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        $answer = self::client($stack)->send($stack->tenant(), $stack->credentials, ApiRequest::get(Operation::ListReturns, self::URI));

        static::assertSame(200, $answer->status());
    }

    public function testAFailingLoggerDoesNotHideATransportFailure(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->tokens->save('urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'), DpaeToken::fromResponseBody(HttpStack::JETON));
        $stack->mock()->addException(new NetworkException('down', $stack->factory->createRequest('GET', self::URI)));

        $exception = Thrown::by(static fn(): HttpAnswer => self::client($stack)->send($stack->tenant(), $stack->credentials, ApiRequest::get(Operation::ListReturns, self::URI)));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame(NetworkException::class, $exception->causeClass());
    }
}
