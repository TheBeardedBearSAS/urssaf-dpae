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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;
use TypeError;

final class XmlApiClientStatusTest extends TestCase
{
    private const URI = 'https://consultation.dpae-edi.urssaf.fr/lister-retours-declarant/1.0/20220130083000';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function read(): ApiRequest
    {
        return ApiRequest::get(Operation::ListReturns, self::URI);
    }

    private static function tokenKey(): string
    {
        return 'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25');
    }

    #[DataProvider('provideOtherStatuses')]
    public function testEveryOtherStatusIsHandedBackUnread(int $status): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue($status, 'not xml, and not gzip', ['Content-Encoding' => 'gzip']);

        $answer = Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read());

        static::assertSame($status, $answer->status());
        static::assertCount(2, $stack->requests());
        static::assertNotNull($stack->tokens->get(self::tokenKey()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideOtherStatuses(): iterable
    {
        yield '200' => [200];
        yield '400' => [400];
        yield '403' => [403];
        yield '404' => [404];
        yield '422' => [422];
        yield '429' => [429];
        yield '500' => [500];
    }

    public function testATransportErrorMayHaveReachedUrssafAndLogsOnlyTheClass(): void
    {
        $stack = self::stack();
        $stack->tokens->save(self::tokenKey(), DpaeToken::fromResponseBody(HttpStack::JETON));
        $stack->mock()->addException(new NetworkException('connect to https://consultation.dpae-edi.urssaf.fr failed', $stack->factory->createRequest('GET', self::URI)));

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame(Operation::ListReturns, $exception->operation());
        static::assertNull($exception->httpStatus());
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertSame(NetworkException::class, $exception->causeClass());
        static::assertStringNotContainsString('consultation.dpae-edi', (string) $exception);
        static::assertSame(
            [['level' => 'warning', 'message' => 'URSSAF call failed: transport error.', 'context' => ['operation' => 'list_returns', 'exception' => NetworkException::class, 'duration_ms' => 5]]],
            $stack->logger->records(),
        );
    }

    public function testAnythingTheClientThrowsIsATransportFailure(): void
    {
        $failure = new TypeError('boom');
        $client = new ThrowingClient($failure);
        $stack = HttpStack::withClient($client, ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->tokens->save(self::tokenKey(), DpaeToken::fromResponseBody(HttpStack::JETON));

        $exception = Thrown::by(static fn(): HttpAnswer => Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read()));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertSame(TypeError::class, $exception->causeClass());
        static::assertSame(1, $client->calls());
    }
}
