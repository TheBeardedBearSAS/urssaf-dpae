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

use Http\Client\Exception\NetworkException;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\ThrowingClient;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;
use TypeError;

final class UrssafHttpGatewayDepositUnknownOutcomeTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    #[DataProvider('provideUnreadableAnswers')]
    public function testAnAnswerThatIsNotTheXmlOfAnEnvoiLeavesTheOutcomeUnknown(int $status, string $body, string $contentType, string $encoding): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $headers = ['Content-Type' => $contentType];
        if ($encoding !== '') {
            $headers['Content-Encoding'] = $encoding;
        }
        $stack->queue($status, $body, $headers);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertTrue($exception->mayHaveReachedUrssaf());
        static::assertStringStartsWith('URSSAF operation deposit answered an unreadable response (', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function provideUnreadableAnswers(): iterable
    {
        yield '200 html page' => [200, '<html>maintenance</html>', 'text/html; charset=iso-8859-1', ''];
        yield '200 xhtml served as html' => [200, '<?xml version="1.0"?><envoi/>', 'text/html', ''];
        yield '200 empty' => [200, '', 'application/xml', ''];
        yield '200 malformed' => [200, '<envoi><IdFluxDPAE>', 'application/xml', ''];
        yield '200 doctype' => [200, '<!DOCTYPE envoi><envoi/>', 'application/xml', ''];
        yield '200 broken gzip' => [200, 'not gzip', 'application/xml', 'gzip'];
        yield '200 unknown coding' => [200, '<envoi/>', 'application/xml', 'br'];
        yield '200 unsupported charset' => [200, '<envoi/>', 'application/xml; charset=UTF-7', ''];
        yield '422 apache page' => [422, '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html>422</html>', 'text/html', ''];
        yield '422 apache page without type' => [422, '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html>422</html>', '', ''];
        yield '422 empty' => [422, '', 'application/xml', ''];
        yield '422 html served as xml' => [422, '<html><body>422', 'application/xml', ''];
    }

    public function testAnAnswerOverTheResponseLimitLeavesTheOutcomeUnknown(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withMaxResponseBytes(10), ServiceCode::direct());
        $stack->tokens->save(
            'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'),
            DpaeToken::fromResponseBody(HttpStack::JETON),
        );
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception->getPrevious());
    }

    public function testATransportErrorAfterTheRequestWasStartedLeavesTheOutcomeUnknown(): void
    {
        $stack = self::stack();
        $stack->tokens->save(
            'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'),
            DpaeToken::fromResponseBody(HttpStack::JETON),
        );
        $stack->mock()->addException(new NetworkException('timeout calling https://depot.dpae-edi.urssaf.fr', $stack->factory->createRequest('POST', 'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0')));

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame(
            'URSSAF operation deposit failed with Http\Client\Exception\NetworkException: the flow may have been received.',
            $exception->getMessage(),
        );
        static::assertStringNotContainsString('depot.dpae-edi', $exception->getMessage());
    }

    public function testAnythingTheClientThrowsLeavesTheOutcomeUnknownAndIsNeverResent(): void
    {
        $client = new ThrowingClient(new TypeError('boom'));
        $stack = HttpStack::withClient($client, ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->tokens->save(
            'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25'),
            DpaeToken::fromResponseBody(HttpStack::JETON),
        );

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertSame('URSSAF operation deposit failed with TypeError: the flow may have been received.', $exception->getMessage());
        static::assertSame(1, $client->calls());
    }

    public function testABodyThatFailsWhileBeingReadLeavesTheOutcomeUnknown(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $resource = fopen('php://memory', 'r+b');
        static::assertIsResource($resource);
        $body = Stream::create($resource);
        fclose($resource);
        $stack->mock()->addResponse($stack->factory->createResponse(200)->withBody($body));

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(OutcomeUnknown::class, $exception);
        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception->getPrevious());
    }
}
