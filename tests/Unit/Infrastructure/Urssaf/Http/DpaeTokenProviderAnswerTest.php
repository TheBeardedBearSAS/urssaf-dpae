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
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

final class DpaeTokenProviderAnswerTest extends TestCase
{
    private const KEY = 'urssaf_dpae.token.';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    public function testATransportErrorIsUnavailableAndLogsOnlyTheClass(): void
    {
        $stack = self::stack();
        $request = $stack->factory->createRequest('POST', 'https://mon.urssaf.fr/authentifier_dpae');
        $stack->mock()->addException(new NetworkException('connect to https://mon.urssaf.fr failed', $request));

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertNull($exception->httpStatus());
        static::assertFalse($exception->mayHaveReachedUrssaf());
        static::assertNull($exception->getPrevious());
        static::assertSame(NetworkException::class, $exception->causeClass());
        static::assertStringNotContainsString('mon.urssaf.fr', (string) $exception);
        static::assertSame(
            [[
                'level' => 'warning',
                'message' => 'URSSAF token request failed: transport error.',
                'context' => ['exception' => NetworkException::class, 'duration_ms' => 5],
            ]],
            $stack->logger->records(),
        );
    }

    public function testAnHtmlAnswerIsNotAToken(): void
    {
        $stack = self::stack();
        $stack->queue(200, '<html>maintenance</html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the token answer is an HTML page.', $exception->getMessage());
    }

    #[DataProvider('provideUnusableTokens')]
    public function testAnEmptyOrDangerousTokenIsUnexpected(string $body): void
    {
        $stack = self::stack();
        $stack->queue(200, $body, ['Content-Type' => 'application/octet-stream']);

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the token is empty or holds control characters.', $exception->getMessage());
        static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
        static::assertNull($stack->tokens->get(self::KEY . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnusableTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [" \r\n"];
        yield 'header injection' => ["abc\r\nX-Evil: 1"];
    }

    public function testACredentialThatXmlCannotCarryIsRefusedBeforeAnyCall(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $credentials = DeclarantCredentials::of(
            $stack->credentials->siret(),
            'John',
            'Doe',
            "Passw0rd-\x01-ok",
            ServiceCode::direct(),
        );

        $exception = Thrown::by(static fn(): DpaeToken => Wiring::provider($stack)->token($stack->tenant(), $credentials, Operation::Deposit));

        static::assertInstanceOf(InvalidValue::class, $exception);
        static::assertSame('authentication.invalid_character', $exception->violationCode());
        static::assertSame([], $stack->requests());
    }
}
