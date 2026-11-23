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

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\HttpAnswer;

final class HttpAnswerStatusTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function answer(int $status, string $body, array $headers, ?int $max): HttpAnswer
    {
        return HttpAnswer::fromResponse(new Response($status, $headers, $body), $max);
    }

    public function testItExposesTheStatusAndTheContentType(): void
    {
        $answer = self::answer(200, '<a/>', ['Content-Type' => 'application/xml; charset=ISO-8859-1'], null);

        static::assertSame(200, $answer->status());
        static::assertSame('application/xml; charset=ISO-8859-1', $answer->contentType());
        static::assertSame('', self::answer(200, '', [], null)->contentType());
    }

    #[DataProvider('provideStatuses')]
    public function testOnlyA2xxIsASuccess(int $status, bool $success): void
    {
        static::assertSame($success, self::answer($status, '', [], null)->isSuccess());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideStatuses(): iterable
    {
        yield '199' => [199, false];
        yield '200' => [200, true];
        yield '204' => [204, true];
        yield '299' => [299, true];
        yield '300' => [300, false];
        yield '401' => [401, false];
        yield '500' => [500, false];
    }

    #[DataProvider('provideContentTypes')]
    public function testItRecognisesAnHtmlPage(string $contentType, bool $html): void
    {
        $headers = $contentType === '' ? [] : ['Content-Type' => $contentType];

        static::assertSame($html, self::answer(200, '', $headers, null)->isHtml());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideContentTypes(): iterable
    {
        yield 'html' => ['text/html', true];
        yield 'html with charset' => ['text/html; charset=iso-8859-1', true];
        yield 'upper case' => ['TEXT/HTML', true];
        yield 'xml' => ['application/xml', false];
        yield 'plain' => ['text/plain', false];
        yield 'pdf' => ['application/pdf', false];
        yield 'none' => ['', false];
    }

    public function testThereIsNoFailureWithoutTheHeader(): void
    {
        static::assertSame(AuthenticationFailure::Unknown, self::answer(401, '', [], null)->authenticationFailure());
    }

    public function testRetryAfterIsReadFromTheHeader(): void
    {
        $now = new DateTimeImmutable('1994-11-06 08:49:37', new DateTimeZone('UTC'));

        static::assertSame(30, self::answer(429, '', ['Retry-After' => '30'], null)->retryAfterSeconds($now));
        static::assertSame(60, self::answer(429, '', ['Retry-After' => 'Sun, 06 Nov 1994 08:50:37 GMT'], null)->retryAfterSeconds($now));
        static::assertNull(self::answer(429, '', [], null)->retryAfterSeconds($now));
    }
}
