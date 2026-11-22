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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;

final class HttpAnswerCodingTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function answer(int $status, string $body, array $headers, ?int $max): HttpAnswer
    {
        return HttpAnswer::fromResponse(new Response($status, $headers, $body), $max);
    }

    #[DataProvider('provideBrokenGzip')]
    public function testACorruptGzipBodyIsRefusedWithoutAWarning(string $body): void
    {
        $this->assertRefused(
            self::answer(200, $body, ['Content-Encoding' => 'gzip'], null),
            'the gzip body cannot be decoded within the size limit',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBrokenGzip(): iterable
    {
        yield 'not gzip' => ['<html>not gzip</html>'];
        yield 'empty' => [''];
        yield 'truncated' => [substr((string) gzencode('<retours><flux/></retours>'), 0, 15)];
    }

    #[DataProvider('provideUnsupportedCodings')]
    public function testAnotherContentCodingIsRefused(string $coding): void
    {
        $this->assertRefused(self::answer(200, 'abc', ['Content-Encoding' => $coding], null), 'the content coding of the body is not supported');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedCodings(): iterable
    {
        yield 'deflate' => ['deflate'];
        yield 'brotli' => ['br'];
        yield 'stacked' => ['gzip, gzip'];
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
