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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiVocabulary;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class EnvoiXmlReaderFieldsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideDates(): iterable
    {
        yield 'DateGeneration' => ['<DateGeneration>20220130083000</DateGeneration>', '2022-01-30T07:30:00Z'];
        yield 'dateGeneration' => ['<dateGeneration>20220130083000</dateGeneration>', '2022-01-30T07:30:00Z'];
        yield 'DateGeneration attribute' => ['<envoi DateGeneration="20220130083000"/>', '2022-01-30T07:30:00Z'];
        yield 'the publication of a retour is not the generation date' => [
            '<retour><publication>20220130083000</publication></retour>',
            null,
        ];
        yield 'DateGeneration wins over dateGeneration' => [
            '<dateGeneration>20220131083000</dateGeneration><DateGeneration>20220130083000</DateGeneration>',
            '2022-01-30T07:30:00Z',
        ];
        yield 'the first date found is not replaced when unreadable' => [
            '<DateGeneration>x</DateGeneration><dateGeneration>20220130083000</dateGeneration>',
            null,
        ];
        yield 'ISO with offset' => ['<dateGeneration>2022-01-30T08:30:00+01:00</dateGeneration>', '2022-01-30T07:30:00Z'];
    }

    #[DataProvider('provideDates')]
    public function testItReadsTheGenerationDate(string $fragment, ?string $expected): void
    {
        $receipt = EnvoiXmlReader::receipt('<envoi>' . $fragment . '</envoi>', '', WireDate::defaultZone());

        static::assertSame($expected, $receipt->generatedAt()?->toIso8601());
    }

    public function testTheSiretIsReadAsAnElementOrAnAttribute(): void
    {
        $element = EnvoiXmlReader::receipt('<envoi><declarant><siret> 99999999999999 </siret></declarant></envoi>', '', WireDate::defaultZone());
        $attribute = EnvoiXmlReader::receipt('<envoi><declarant siret="88888888888888"/></envoi>', '', WireDate::defaultZone());

        static::assertSame('99999999999999', $element->declarantSiret()?->value());
        static::assertSame('88888888888888', $attribute->declarantSiret()?->value());
    }

    public function testTheDeclarantSiretWinsOverAnyOtherSiret(): void
    {
        $xml = '<envoi><siret>11111111111111</siret><envoi_identification><declarant><nom>X</nom></declarant>'
            . '<declarant siret="22222222222222"/></envoi_identification></envoi>';

        static::assertSame('22222222222222', EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone())->declarantSiret()?->value());
        static::assertSame(
            '11111111111111',
            EnvoiXmlReader::receipt('<envoi><siret>11111111111111</siret><declarant/></envoi>', '', WireDate::defaultZone())->declarantSiret()?->value(),
        );
    }

    public function testTheVocabularyIsConfigurable(): void
    {
        $vocabulary = EnvoiVocabulary::of('Flux', ['Genere'], ['raison']);
        $xml = '<avis><Flux>F1</Flux><IdFluxDPAE>IGNORED</IdFluxDPAE><Genere>20220130083000</Genere>'
            . '<DateGeneration>20220131083000</DateGeneration><raison>r1</raison><motif>ignored</motif></avis>';
        $notice = EnvoiXmlReader::rejectionWith($xml, '', WireDate::defaultZone(), $vocabulary);
        $receipt = EnvoiXmlReader::receiptWith($xml, '', WireDate::defaultZone(), $vocabulary);

        static::assertSame('F1', $notice->flowId()?->value());
        static::assertSame('2022-01-30T07:30:00Z', $notice->generatedAt()?->toIso8601());
        static::assertSame(['r1'], $notice->reasons());
        static::assertSame('F1', $receipt->flowId()?->value());
    }

    public function testRejectReasonsAreCollectedFromEveryKnownName(): void
    {
        $xml = '<avis><motif>un</motif><motifRejet> deux </motifRejet><MotifRejet>trois</MotifRejet><motif/><motif>  </motif><autre>x</autre></avis>';

        static::assertSame(['un', 'deux', 'trois'], EnvoiXmlReader::rejection($xml, '', WireDate::defaultZone())->reasons());
    }
}
