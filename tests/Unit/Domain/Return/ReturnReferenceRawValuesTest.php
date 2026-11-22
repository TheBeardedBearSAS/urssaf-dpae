<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;

final class ReturnReferenceRawValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string, ?string}>
     */
    public static function provideValuesOutsideThePublishedBounds(): iterable
    {
        yield 'status empty' => ['', null, null, null];
        yield 'status blank' => [' ', null, null, null];
        yield 'status long' => [str_repeat('K', 100), null, null, null];
        yield 'return id empty' => ['OK', '', null, null];
        yield 'return id 11' => ['OK', str_repeat('1', 11), null, null];
        yield 'ops 4' => ['OK', null, 'ABCD', null];
        yield 'ops empty' => ['OK', null, '', null];
        yield 'ops 11' => ['OK', null, 'ABCDEFGHIJK', null];
        yield 'delegate 4' => ['OK', null, null, 'ABCD'];
        yield 'delegate 10' => ['OK', null, null, 'ABCDEFGHIJ'];
        yield 'invalid utf-8' => ['OK', "\xFF", "\xFF", "\xFF"];
    }

    #[DataProvider('provideValuesOutsideThePublishedBounds')]
    public function testAnUnexpectedValueNeverBreaksARead(string $status, ?string $returnId, ?string $ops, ?string $delegate): void
    {
        $ref = $this->build($status, $returnId, $ops, $delegate, null);

        static::assertSame($status, $ref->statusCode());
        static::assertSame($returnId, $ref->returnId());
        static::assertSame($ops, $ref->ops());
        static::assertSame($delegate, $ref->delegate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlankUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [' '];
    }

    #[DataProvider('provideBlankUrls')]
    public function testABlankUrlIsRefused(string $url): void
    {
        try {
            $this->build('OK', null, null, null, $url);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('return_url.empty', $e->violationCode());
        }
    }

    public function testTheDownloadIdIsCheckedWhenAskedFor(): void
    {
        $ref = $this->build('OK', str_repeat('1', 11), null, null, null);

        try {
            $ref->downloadId();
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('return_id.too_long', $e->violationCode());
        }
    }

    private function build(
        string $status,
        ?string $returnId,
        ?string $ops,
        ?string $delegate,
        ?string $url,
    ): ReturnReference {
        return ReturnReference::fromListing(
            FlowId::fromString('F1'),
            10,
            $status,
            '20220130083000',
            '20220130083000',
            null,
            null,
            $returnId,
            $ops,
            $delegate,
            $url,
        );
    }
}
