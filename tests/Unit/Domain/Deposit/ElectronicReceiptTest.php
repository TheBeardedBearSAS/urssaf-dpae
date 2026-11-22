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
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final class ElectronicReceiptTest extends TestCase
{
    public function testItKeepsEveryExtractedFieldAndTheRawDocument(): void
    {
        $flowId = FlowId::fromString('F1');
        $siret = Siret::fromString('99999999999999');
        $generatedAt = Instant::fromIso8601('2022-02-01T16:40:00Z');
        $document = RawDocument::of("<envoi>\n  <x/>\n</envoi>", 'application/xml');

        $result = ElectronicReceipt::of($flowId, $siret, $generatedAt, $document);

        static::assertSame($flowId, $result->flowId());
        static::assertSame($siret, $result->declarantSiret());
        static::assertSame($generatedAt, $result->generatedAt());
        static::assertSame($document, $result->document());
    }

    public function testEveryExtractedFieldMayBeUnknown(): void
    {
        $result = ElectronicReceipt::of(null, null, null, RawDocument::of('<envoi/>', ''));

        static::assertNull($result->flowId());
        static::assertNull($result->declarantSiret());
        static::assertNull($result->generatedAt());
    }

    public function testDebugInfoMasksTheDocument(): void
    {
        $result = ElectronicReceipt::of(
            FlowId::fromString('F1'),
            Siret::fromString('99999999999999'),
            Instant::fromIso8601('2022-02-01T16:40:00+01:00'),
            RawDocument::of('<envoi>Jane</envoi>', 'application/xml'),
        );

        static::assertSame(
            [
                'flowId' => 'F1',
                'generatedAt' => '2022-02-01T15:40:00Z',
                'document' => ['size' => 19, 'sha256' => hash('sha256', '<envoi>Jane</envoi>'), 'contentType' => 'application/xml'],
            ],
            $result->__debugInfo(),
        );
        static::assertStringNotContainsString('Jane', print_r($result, true));
    }

    public function testDebugInfoOfAnEmptyReceipt(): void
    {
        $result = ElectronicReceipt::of(null, null, null, RawDocument::of('x', ''));

        static::assertSame(
            ['flowId' => null, 'generatedAt' => null, 'document' => ['size' => 1, 'sha256' => hash('sha256', 'x'), 'contentType' => '']],
            $result->__debugInfo(),
        );
    }
}
