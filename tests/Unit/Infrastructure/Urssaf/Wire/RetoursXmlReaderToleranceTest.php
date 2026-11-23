<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\RetoursXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class RetoursXmlReaderToleranceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures/wire/';

    /**
     * @return list<ReturnReference>
     */
    private static function readFixture(string $name): array
    {
        return RetoursXmlReader::read((string) file_get_contents(self::FIXTURES . $name), 'application/xml', WireDate::defaultZone());
    }

    /**
     * @return list<ReturnReference>
     */
    private static function readXml(string $xml): array
    {
        return RetoursXmlReader::read($xml, '', WireDate::defaultZone());
    }

    private const MINIMAL = '<publication>20220130083000</publication><production>20220130082500</production><nature>10</nature><statut>OK</statut>';

    public function testUnknownValuesAndElementsNeverBreakTheRead(): void
    {
        $references = self::readFixture('retours_unknown_values.xml');

        static::assertCount(1, $references);
        $reference = $references[0];
        static::assertSame('FICTIF0000000000000003', $reference->flowId()->value());
        static::assertSame(99, $reference->natureCode());
        static::assertNull($reference->nature());
        static::assertSame('NOUVEAU_STATUT', $reference->statusCode());
        static::assertNull($reference->status());
        static::assertSame('pas une date', $reference->publicationRaw());
        static::assertNull($reference->publishedAt());
        static::assertSame('2022-02-28T23:00:00Z', $reference->producedAt()?->toIso8601());
    }

    public function testAFluxWithoutReturnGivesNothing(): void
    {
        static::assertSame([], self::readXml('<retours><flux><id>F1</id></flux></retours>'));
    }

    public function testAnEmptyFluxDoesNotStopTheReadOfTheNextOnes(): void
    {
        $retour = '<retour><publication>20220130083000</publication><production>20220130082500</production>'
            . '<nature>10</nature><statut>OK</statut></retour>';

        $references = self::readXml('<retours><flux><id>F1</id></flux><flux><id>F2</id>' . $retour . '</flux></retours>');

        static::assertCount(1, $references);
        static::assertSame('F2', $references[0]->flowId()->value());
    }

    public function testAnEmptyListIsAnEmptyRetoursRoot(): void
    {
        static::assertSame([], self::readXml('<retours/>'));
        static::assertSame([], self::readXml('<?xml version="1.0"?><n:retours xmlns:n="urn:x"/>'));
    }

    public function testAFluxRootIsAcceptedWhateverTheWrapper(): void
    {
        $references = self::readXml('<liste><flux><id>F1</id><retour>' . self::MINIMAL . '</retour></flux></liste>');

        static::assertCount(1, $references);
        static::assertSame('F1', $references[0]->flowId()->value());
    }

    public function testNamespacesAreIgnored(): void
    {
        $references = self::readXml('<n:retours xmlns:n="urn:x"><n:flux><n:id>F1</n:id><n:retour><n:publication>20220130083000</n:publication>'
            . '<n:production>20220130082500</n:production><n:nature>10</n:nature><n:statut>OK</n:statut></n:retour></n:flux></n:retours>');

        static::assertCount(1, $references);
    }
}
