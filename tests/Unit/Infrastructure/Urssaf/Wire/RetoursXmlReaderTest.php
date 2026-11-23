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

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnStatus;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\RetoursXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class RetoursXmlReaderTest extends TestCase
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

    private static function wrap(string $retour): string
    {
        return '<retours><flux><id>F1</id><retour>' . $retour . '</retour></flux></retours>';
    }

    private const MINIMAL = '<publication>20220130083000</publication><production>20220130082500</production><nature>10</nature><statut>OK</statut>';

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideShapes(): iterable
    {
        yield 'child elements' => ['retours_children.xml'];
        yield 'attributes' => ['retours_attributes.xml'];
    }

    #[DataProvider('provideShapes')]
    public function testItReadsEveryFieldInEitherShape(string $fixture): void
    {
        $references = self::readFixture($fixture);

        static::assertCount(3, $references);

        $first = $references[0];
        static::assertSame('FICTIF0000000000000001', $first->flowId()->value());
        static::assertSame(10, $first->natureCode());
        static::assertSame(ReturnNature::fromCode(10), $first->nature());
        static::assertSame('OK', $first->statusCode());
        static::assertSame(ReturnStatus::fromCode('OK'), $first->status());
        static::assertSame('20220130083000', $first->publicationRaw());
        static::assertSame('20220130082500', $first->productionRaw());
        static::assertSame('2022-01-30T07:30:00Z', $first->publishedAt()?->toIso8601());
        static::assertSame('2022-01-30T07:25:00Z', $first->producedAt()?->toIso8601());
        static::assertSame('0123456789', $first->returnId());
        static::assertNull($first->ops());
        static::assertNull($first->delegate());
        static::assertSame('https://telechargement.example.test/telecharger-retour/1.0/FICTIF0000000000000001/0123456789', $first->url());

        $second = $references[1];
        static::assertSame('FICTIF0000000000000001', $second->flowId()->value());
        static::assertSame(41, $second->natureCode());
        static::assertSame('EN_COURS', $second->statusCode());
        static::assertSame('OPS12', $second->ops());
        static::assertSame('DELEG', $second->delegate());
        static::assertNull($second->returnId());
        static::assertNull($second->url());
        static::assertFalse($second->hasDocument());

        $third = $references[2];
        static::assertSame('FICTIF0000000000000002', $third->flowId()->value());
        static::assertSame('KO', $third->statusCode());
    }

    public function testBothShapesGiveTheSameReferences(): void
    {
        $summary = static fn(ReturnReference $r): array => [
            $r->flowId()->value(), $r->natureCode(), $r->statusCode(), $r->publicationRaw(), $r->productionRaw(),
            $r->publishedAt()?->toIso8601(), $r->producedAt()?->toIso8601(), $r->returnId(), $r->ops(), $r->delegate(), $r->url(),
        ];

        static::assertSame(
            array_map($summary, self::readFixture('retours_children.xml')),
            array_map($summary, self::readFixture('retours_attributes.xml')),
        );
    }

    public function testTheConfiguredZoneIsUsed(): void
    {
        $references = RetoursXmlReader::read(self::wrap(self::MINIMAL), 'application/xml', WireDate::inZone(new DateTimeZone('UTC')));

        static::assertSame('2022-01-30T08:30:00Z', $references[0]->publishedAt()?->toIso8601());
    }

    public function testTheBodyIsReadWithItsEncoding(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><retours><flux><id>caf\xE9</id><retour>" . self::MINIMAL . '</retour></flux></retours>';

        static::assertSame('café', self::readXml($xml)[0]->flowId()->value());
    }

    public function testAnEmptyOptionalFieldIsAbsent(): void
    {
        $reference = self::readXml(self::wrap(self::MINIMAL . '<id/><ops> </ops><delegataire/><url/>'))[0];

        static::assertNull($reference->returnId());
        static::assertNull($reference->ops());
        static::assertNull($reference->delegate());
        static::assertNull($reference->url());
    }
}
