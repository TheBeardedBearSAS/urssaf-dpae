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
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\SteppingClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;
use Throwable;

final class UrssafHttpGatewayDownloadReturnFailureTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

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

    #[DataProvider('provideServerErrors')]
    public function testAServerErrorIsTransientAndSafeToRetry(int $status): void
    {
        $exception = $this->failWith($status);

        static::assertInstanceOf(UrssafUnavailable::class, $exception);
        static::assertSame($status, $exception->httpStatus());
        static::assertSame(Operation::DownloadReturn, $exception->operation());
        static::assertSame(30, $exception->retryAfterSeconds());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideServerErrors(): iterable
    {
        yield '500' => [500];
        yield '503' => [503];
    }

    #[DataProvider('provideUnexpectedStatuses')]
    public function testAnyOtherStatusIsUnexpected(int $status): void
    {
        $exception = $this->failWith($status);

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(\sprintf('Unexpected URSSAF response: operation download_return answered HTTP %d.', $status), $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideUnexpectedStatuses(): iterable
    {
        yield '302' => [302];
        yield '400' => [400];
        yield '406' => [406];
        yield '415' => [415];
        yield '499' => [499];
    }

    public function testAnHtmlPageWithA200IsUnexpected(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<html>maintenance</html>', ['Content-Type' => 'text/html']);

        $exception = Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', '1', null)));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the answer is an HTML page.', $exception->getMessage());
    }

    public function testAnEmptyDocumentIsUnexpected(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '', ['Content-Type' => 'application/pdf']);

        $exception = Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', '1', null)));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the downloaded document is empty.', $exception->getMessage());
        static::assertInstanceOf(InvalidValue::class, $exception->getPrevious());
    }

    private function failWith(int $status): Throwable
    {
        $stack = HttpStack::withClock(new SteppingClock(0), ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue($status, '<html/>', ['Content-Type' => 'text/html', 'Retry-After' => '30']);

        return Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', '1', null)));
    }
}
