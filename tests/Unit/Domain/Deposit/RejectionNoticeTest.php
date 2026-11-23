<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Deposit;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final class RejectionNoticeTest extends TestCase
{
    public function testItKeepsEveryExtractedFieldTheReasonsAndTheRawDocument(): void
    {
        $flowId = FlowId::fromString('F1');
        $siret = Siret::fromString('99999999999999');
        $generatedAt = Instant::fromIso8601('2022-02-01T16:40:00Z');
        $document = RawDocument::of('<envoi/>', 'application/xml');

        $result = RejectionNotice::of($flowId, $siret, $generatedAt, $document, ['R1 label', 'R2']);

        static::assertSame($flowId, $result->flowId());
        static::assertSame($siret, $result->declarantSiret());
        static::assertSame($generatedAt, $result->generatedAt());
        static::assertSame($document, $result->document());
        static::assertSame(['R1 label', 'R2'], $result->reasons());
    }

    public function testEveryExtractedFieldAndTheReasonsMayBeAbsent(): void
    {
        $result = RejectionNotice::of(null, null, null, RawDocument::of('<envoi/>', ''), []);

        static::assertNull($result->flowId());
        static::assertNull($result->declarantSiret());
        static::assertNull($result->generatedAt());
        static::assertSame([], $result->reasons());
    }

    public function testDebugInfoMasksTheDocumentAndTheReasons(): void
    {
        $result = RejectionNotice::of(
            FlowId::fromString('F1'),
            null,
            Instant::fromIso8601('2022-02-01T16:40:00Z'),
            RawDocument::of('<envoi>Jane</envoi>', 'application/xml'),
            ['Jane is unknown'],
        );

        static::assertSame(
            [
                'flowId' => 'F1',
                'generatedAt' => '2022-02-01T16:40:00Z',
                'document' => ['size' => 19, 'sha256' => hash('sha256', '<envoi>Jane</envoi>'), 'contentType' => 'application/xml'],
                'reasonCount' => 1,
            ],
            $result->__debugInfo(),
        );
        static::assertStringNotContainsString('Jane', print_r($result, true));
    }

    public function testDebugInfoOfAnEmptyNotice(): void
    {
        $result = RejectionNotice::of(null, null, null, RawDocument::of('x', ''), []);

        static::assertSame(
            ['flowId' => null, 'generatedAt' => null, 'document' => ['size' => 1, 'sha256' => hash('sha256', 'x'), 'contentType' => ''], 'reasonCount' => 0],
            $result->__debugInfo(),
        );
    }
}
