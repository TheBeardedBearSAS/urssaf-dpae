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
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;
use Throwable;

final class HttpAnswerBodyTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function answer(int $status, string $body, array $headers, ?int $max): HttpAnswer
    {
        return HttpAnswer::fromResponse(new Response($status, $headers, $body), $max);
    }

    public function testTheBodyIsReturnedAsIs(): void
    {
        static::assertSame("<a>\xE9</a>", self::answer(200, "<a>\xE9</a>", [], null)->body());
        static::assertSame('', self::answer(200, '', [], 1)->body());
        static::assertSame('abc', self::answer(200, 'abc', ['Content-Encoding' => 'identity'], null)->body());
        static::assertSame('abc', self::answer(200, 'abc', ['Content-Encoding' => ' IDENTITY '], null)->body());
    }

    public function testAGzipBodyIsDecoded(): void
    {
        $gzip = (string) gzencode('<retours/>');

        static::assertSame('<retours/>', self::answer(200, $gzip, ['Content-Encoding' => 'gzip'], null)->body());
        static::assertSame('<retours/>', self::answer(200, $gzip, ['Content-Encoding' => 'GZIP'], 1000)->body());
        static::assertSame('<retours/>', self::answer(200, $gzip, ['Content-Encoding' => ' x-gzip '], 1000)->body());
    }

    public function testTheSizeLimitIsInclusive(): void
    {
        static::assertSame('abcd', self::answer(200, 'abcd', [], 4)->body());
        static::assertSame('abcd', self::answer(200, (string) gzencode('abcd'), ['Content-Encoding' => 'gzip'], 4)->body());
    }

    public function testABodyOverTheLimitIsRefused(): void
    {
        $this->assertRefused(self::answer(200, 'abcde', [], 4), 'the body exceeds the size limit');
    }

    public function testADecodedBodyOverTheLimitIsRefusedWithoutBeingInflated(): void
    {
        $bomb = (string) gzencode(str_repeat('A', 1_000_000));

        static::assertLessThan(10_000, \strlen($bomb));
        $this->assertRefused(
            self::answer(200, $bomb, ['Content-Encoding' => 'gzip'], 1000),
            'the gzip body cannot be decoded within the size limit',
        );
    }

    public function testWithoutALimitALargeGzipBodyIsDecoded(): void
    {
        $body = str_repeat('A', 200_000);

        static::assertSame($body, self::answer(200, (string) gzencode($body), ['Content-Encoding' => 'gzip'], null)->body());
    }

    public function testABodyThatCannotBeReadIsRefusedWithItsCause(): void
    {
        $resource = fopen('php://memory', 'r+b');
        static::assertIsResource($resource);
        $stream = Stream::create($resource);
        fclose($resource);

        $exception = Thrown::by(HttpAnswer::fromResponse(new Response(200, [], $stream), null)->body(...));

        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: the body cannot be read.', $exception->getMessage());
        static::assertInstanceOf(Throwable::class, $exception->getPrevious());
    }

    private function assertRefused(HttpAnswer $answer, string $reason): void
    {
        // The warning of gzdecode() is handled, never left to PHP's own handler (which would record it).
        error_clear_last();
        $exception = Thrown::by($answer->body(...));

        static::assertNull(error_get_last());
        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
        static::assertSame('Unexpected URSSAF response: ' . $reason . '.', $exception->getMessage());
    }
}
