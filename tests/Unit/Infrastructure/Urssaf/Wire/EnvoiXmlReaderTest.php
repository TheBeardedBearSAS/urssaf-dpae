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
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class EnvoiXmlReaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures/wire/';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    public function testItExtractsTheReceipt(): void
    {
        $bytes = self::fixture('envoi_receipt.xml');
        $receipt = EnvoiXmlReader::receipt($bytes, 'application/xml; charset=ISO-8859-1', WireDate::defaultZone());

        static::assertSame('FICTIF0000000000000001', $receipt->flowId()?->value());
        static::assertSame('99999999999999', $receipt->declarantSiret()?->value());
        static::assertSame('2022-01-30T07:30:00Z', $receipt->generatedAt()?->toIso8601());
        static::assertSame($bytes, $receipt->document()->bytes());
        static::assertSame('application/xml; charset=ISO-8859-1', $receipt->document()->contentType());
    }

    public function testItExtractsTheRejectionNotice(): void
    {
        $bytes = self::fixture('envoi_rejection.xml');
        $notice = EnvoiXmlReader::rejection($bytes, 'application/xml', WireDate::defaultZone());

        static::assertSame('FICTIF0000000000000002', $notice->flowId()?->value());
        static::assertSame('99999999999999', $notice->declarantSiret()?->value());
        static::assertSame('2022-01-31T09:15:00Z', $notice->generatedAt()?->toIso8601());
        static::assertSame(['Version de la norme inconnue', 'SIRET employeur absent'], $notice->reasons());
        static::assertSame($bytes, $notice->document()->bytes());
        static::assertSame('application/xml', $notice->document()->contentType());
    }

    public function testWhatIsAbsentIsNullAndTheDocumentIsKept(): void
    {
        $receipt = EnvoiXmlReader::receipt('<envoi/>', 'application/xml', WireDate::defaultZone());
        static::assertNull($receipt->flowId());
        static::assertNull($receipt->declarantSiret());
        static::assertNull($receipt->generatedAt());
        static::assertSame('<envoi/>', $receipt->document()->bytes());

        $notice = EnvoiXmlReader::rejection('<avis><autre>x</autre></avis>', '', WireDate::defaultZone());
        static::assertNull($notice->flowId());
        static::assertNull($notice->declarantSiret());
        static::assertNull($notice->generatedAt());
        static::assertSame([], $notice->reasons());
        static::assertSame('', $notice->document()->contentType());
    }

    public function testTheFluxIdIsTheFallbackOfTheFlowId(): void
    {
        $xml = '<envoi><envoi_bilan><envoi_etat><flux><id>F9</id></flux></envoi_etat></envoi_bilan></envoi>';
        static::assertSame('F9', EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone())->flowId()?->value());

        $xml = '<envoi><flux id="F8"/></envoi>';
        static::assertSame('F8', EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone())->flowId()?->value());

        $xml = '<envoi><flux><id>F9</id></flux><IdFluxDPAE>F1</IdFluxDPAE></envoi>';
        static::assertSame('F1', EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone())->flowId()?->value());

        $xml = '<envoi IdFluxDPAE="F7"/>';
        static::assertSame('F7', EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone())->flowId()?->value());
    }

    public function testAnUnusableValueIsNullNeverAnError(): void
    {
        $xml = '<envoi><IdFluxDPAE>' . str_repeat('x', 51) . '</IdFluxDPAE><siret>123</siret><DateGeneration>demain</DateGeneration></envoi>';
        $receipt = EnvoiXmlReader::receipt($xml, '', WireDate::defaultZone());

        static::assertNull($receipt->flowId());
        static::assertNull($receipt->declarantSiret());
        static::assertNull($receipt->generatedAt());
    }

    public function testTheConfiguredZoneIsUsed(): void
    {
        $receipt = EnvoiXmlReader::receipt('<envoi><DateGeneration>20220130083000</DateGeneration></envoi>', '', WireDate::inZone(new DateTimeZone('UTC')));

        static::assertSame('2022-01-30T08:30:00Z', $receipt->generatedAt()?->toIso8601());
    }

    public function testAnUnreadableBodyIsRefusedForTheReceipt(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        EnvoiXmlReader::receipt('<html><body>Apache</body>', 'text/html', WireDate::defaultZone());
    }

    public function testAnUnreadableBodyIsRefusedForTheRejection(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        EnvoiXmlReader::rejection('<html><body>Apache</body>', 'text/html', WireDate::defaultZone());
    }

    public function testADoctypeIsRefused(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        EnvoiXmlReader::rejection('<!DOCTYPE envoi [<!ENTITY e "x">]><envoi>&e;</envoi>', 'application/xml', WireDate::defaultZone());
    }
}
