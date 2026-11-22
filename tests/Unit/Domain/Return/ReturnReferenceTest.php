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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnStatus;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final class ReturnReferenceTest extends TestCase
{
    public function testItExposesEveryField(): void
    {
        $published = Instant::fromIso8601('2022-01-30T08:30:00Z');
        $produced = Instant::fromIso8601('2022-01-30T08:00:00Z');
        $flow = FlowId::fromString('F1spdeAilkR4q3UUlQA7haa');

        $ref = ReturnReference::fromListing(
            $flow,
            10,
            'OK',
            '20220130083000',
            '20220130080000',
            $published,
            $produced,
            '0123456789',
            'SI DPAE',
            'X2ACF',
            'https://telechargement.example.test/telecharger-retour/1.0/F1/0123456789',
        );

        static::assertSame($flow, $ref->flowId());
        static::assertSame(10, $ref->natureCode());
        static::assertSame(ReturnNature::ReceiptOrRejection, $ref->nature());
        static::assertSame('OK', $ref->statusCode());
        static::assertSame(ReturnStatus::Ok, $ref->status());
        static::assertSame('20220130083000', $ref->publicationRaw());
        static::assertSame('20220130080000', $ref->productionRaw());
        static::assertSame($published, $ref->publishedAt());
        static::assertSame($produced, $ref->producedAt());
        static::assertSame('0123456789', $ref->returnId());
        static::assertSame('SI DPAE', $ref->ops());
        static::assertSame('X2ACF', $ref->delegate());
        static::assertSame('https://telechargement.example.test/telecharger-retour/1.0/F1/0123456789', $ref->url());
        static::assertTrue($ref->hasDocument());
        static::assertSame('0123456789', $ref->downloadId()?->value());
    }

    public function testUnknownNatureAndStatusArePreserved(): void
    {
        $ref = ReturnReference::fromListing(
            FlowId::fromString('F1'),
            50,
            'WEIRD',
            'x',
            'y',
            null,
            null,
            null,
            null,
            null,
            null,
        );

        static::assertSame(50, $ref->natureCode());
        static::assertNull($ref->nature());
        static::assertSame('WEIRD', $ref->statusCode());
        static::assertNull($ref->status());
        static::assertSame('x', $ref->publicationRaw());
        static::assertSame('y', $ref->productionRaw());
    }

    public function testAnAbsentOptionalFieldIsNull(): void
    {
        $ref = $this->build('KO', null, null, null, null);

        static::assertNull($ref->publishedAt());
        static::assertNull($ref->producedAt());
        static::assertNull($ref->returnId());
        static::assertNull($ref->ops());
        static::assertNull($ref->delegate());
        static::assertNull($ref->url());
        static::assertNull($ref->downloadId());
        static::assertFalse($ref->hasDocument());
    }

    public function testADocumentIsAssociatedByUrlOrByLegacyId(): void
    {
        static::assertTrue($this->build('OK', null, null, null, 'https://x.test/d')->hasDocument());
        static::assertTrue($this->build('OK', '1', null, null, null)->hasDocument());
    }

    public function testTheIdentityIsFlowNatureAndOps(): void
    {
        $ref = ReturnReference::fromListing(
            FlowId::fromString('F9'),
            11,
            'OK',
            'x',
            'y',
            null,
            null,
            null,
            'ABCDE',
            null,
            null,
        );

        static::assertTrue($ref->identity()->equals(new ReturnIdentity(FlowId::fromString('F9'), 11, 'ABCDE')));
        static::assertNull($this->build('OK', null, null, null, null)->identity()->ops());
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
