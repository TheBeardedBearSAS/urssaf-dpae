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

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;

final class HttpAnswerStreamTest extends TestCase
{
    /**
     * A seekable stream that counts the bytes and the calls of `read()`, and must never be cast to a string. `read()` yields at most
     * `$yieldLimit` bytes: 0 makes it stall on an empty string without reaching the end.
     */
    private function stream(string $data, ReadCounter $counter, int $yieldLimit): StreamInterface
    {
        $position = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(static::never())->method('__toString');
        $stream->method('isSeekable')->willReturn(true);
        $stream->expects(static::once())->method('rewind')->willReturnCallback(static function () use (&$position): void {
            $position = 0;
        });
        $stream->method('eof')->willReturnCallback(static function () use (&$position, $data): bool {
            return $position >= \strlen($data);
        });
        $stream->method('read')->willReturnCallback(static function (int $length) use (&$position, $counter, $data, $yieldLimit): string {
            if ($yieldLimit === 0 && $counter->reads() >= 3) {
                self::fail('The stalled stream was read again and again.');
            }

            $chunk = substr($data, $position, min($length, $yieldLimit));
            $position += \strlen($chunk);
            $counter->record(\strlen($chunk));

            return $chunk;
        });

        return $stream;
    }

    public function testACompressedBodyFarOverTheLimitIsRefusedWhileBeingRead(): void
    {
        $counter = new ReadCounter();
        $stream = $this->stream(random_bytes(50_000), $counter, PHP_INT_MAX);

        $exception = Thrown::by(HttpAnswer::fromResponse(new Response(200, ['Content-Encoding' => 'gzip'], $stream), 100)->body(...));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the body exceeds the size limit.', $exception->getMessage());
        static::assertSame(100 + 1024 + 1, $counter->bytes());
    }

    public function testAnIdentityBodyIsReadByBlocksUpToTheLimitPlusOneByte(): void
    {
        $counter = new ReadCounter();
        $stream = $this->stream(str_repeat('a', 100_000), $counter, PHP_INT_MAX);

        $exception = Thrown::by(HttpAnswer::fromResponse(new Response(200, [], $stream), 20_000)->body(...));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(20_001, $counter->bytes());
        static::assertSame(3, $counter->reads());
    }

    public function testABodyOverALimitMadeOfWholeBlocksIsStillRefused(): void
    {
        $counter = new ReadCounter();
        $stream = $this->stream(str_repeat('a', 100_000), $counter, PHP_INT_MAX);

        $exception = Thrown::by(HttpAnswer::fromResponse(new Response(200, [], $stream), 16_384)->body(...));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame(16_385, $counter->bytes());
    }

    public function testABodyAtTheLimitIsReadWholeByBlocks(): void
    {
        $counter = new ReadCounter();
        $body = str_repeat('a', 20_000);

        static::assertSame($body, HttpAnswer::fromResponse(new Response(200, [], $this->stream($body, $counter, PHP_INT_MAX)), 20_000)->body());
        static::assertSame(20_000, $counter->bytes());
    }

    public function testABodyWithoutALimitIsReadWholeByBlocks(): void
    {
        $counter = new ReadCounter();
        $body = str_repeat('a', 30_000);

        static::assertSame($body, HttpAnswer::fromResponse(new Response(200, [], $this->stream($body, $counter, PHP_INT_MAX)), null)->body());
        static::assertSame(4, $counter->reads());
    }

    public function testAStreamThatYieldsNothingEndsTheBody(): void
    {
        $counter = new ReadCounter();

        static::assertSame('', HttpAnswer::fromResponse(new Response(200, [], $this->stream('abc', $counter, 0)), 10)->body());
        static::assertSame(1, $counter->reads());
    }

    public function testAStreamThatIsNotSeekableIsNotRewound(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(false);
        $stream->expects(static::never())->method('rewind');
        $stream->method('eof')->willReturn(false, true);
        $stream->method('read')->willReturn('abc');

        static::assertSame('abc', HttpAnswer::fromResponse(new Response(200, [], $stream), 10)->body());
    }

    public function testAStreamAlreadyReadIsReadFromItsStart(): void
    {
        $stream = Stream::create('abc');

        static::assertSame('abc', HttpAnswer::fromResponse(new Response(200, [], $stream), 10)->body());
        static::assertSame('abc', HttpAnswer::fromResponse(new Response(200, [], $stream), 10)->body());
    }

    public function testACompressedBodyIsReadUpToTheLimitPlusItsOverheadPlusOneByte(): void
    {
        $limit = 1_048_576;
        $allowed = $limit + 1024 + 1024;

        $accepted = Thrown::by(HttpAnswer::fromResponse(new Response(200, ['Content-Encoding' => 'gzip'], random_bytes($allowed)), $limit)->body(...));
        $refused = Thrown::by(HttpAnswer::fromResponse(new Response(200, ['Content-Encoding' => 'gzip'], random_bytes($allowed + 1)), $limit)->body(...));

        static::assertSame('Unexpected URSSAF response: the gzip body cannot be decoded within the size limit.', $accepted->getMessage());
        static::assertSame('Unexpected URSSAF response: the body exceeds the size limit.', $refused->getMessage());
    }
}
