<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class UrssafHttpGatewayDownloadReturnPathTest extends TestCase
{
    private const DOWNLOAD = 'https://telechargement.dpae-edi.urssaf.fr';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function reference(string $flowId, ?string $returnId, ?string $url): ReturnReference
    {
        return ReturnReference::fromListing(
            FlowId::fromString($flowId),
            10,
            'OK',
            '20220130083000',
            '20220130082500',
            null,
            null,
            $returnId,
            null,
            null,
            $url,
        );
    }

    private static function download(HttpStack $stack, ReturnReference $return): ReturnContent
    {
        return $stack->gateway()->downloadReturn($stack->tenant(), $return);
    }

    public function testTheDocumentIsDownloadedFromThePathRebuiltFromTheIdentifiers(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml; charset=ISO-8859-1']);

        $content = self::download($stack, self::reference('F1', '0123456789', null));

        $requests = $stack->requests();
        static::assertCount(2, $requests);
        static::assertSame('GET', $requests[1]->getMethod());
        static::assertSame(self::DOWNLOAD . '/telecharger-retour/1.0/F1/0123456789', (string) $requests[1]->getUri());
        static::assertSame('DPAELogin jeton=' . HttpStack::JETON, $requests[1]->getHeaderLine('Authorization'));
        static::assertSame('gzip', $requests[1]->getHeaderLine('Accept-Encoding'));
        static::assertSame(Fixtures::file('envoi_receipt.xml'), $content->bytes());
        static::assertSame('application/xml; charset=ISO-8859-1', $content->contentType());
    }

    public function testABinaryDocumentIsKeptByteForByte(): void
    {
        $pdf = "%PDF-1.4\n\xFF\xFE\x00binary\r\n%%EOF";
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, $pdf, ['Content-Type' => 'application/pdf']);

        $content = self::download($stack, self::reference('F1', '1', null));

        static::assertSame($pdf, $content->bytes());
        static::assertSame('application/pdf', $content->contentType());
    }

    public function testAGzippedDocumentIsDecoded(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, (string) gzencode('<envoi/>'), ['Content-Type' => 'application/xml', 'Content-Encoding' => 'gzip']);

        static::assertSame('<envoi/>', self::download($stack, self::reference('F1', '1', null))->bytes());
    }

    public function testTheIdentifiersAreEncodedInThePath(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<a/>', ['Content-Type' => 'application/xml']);

        self::download($stack, self::reference('F 1/../é?#', 'a b/?', null));

        static::assertSame(
            self::DOWNLOAD . '/telecharger-retour/1.0/F%201%2F..%2F%C3%A9%3F%23/a%20b%2F%3F',
            (string) $stack->requests()[1]->getUri(),
        );
    }

    public function testAConcentratorDownloadsFromTheDownloadHostWithThePort(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, '<a/>', ['Content-Type' => 'application/xml']);

        self::download($stack, self::reference('F1', '1', null));

        static::assertSame(self::DOWNLOAD . ':8443/telecharger-retour/1.0/F1/1', (string) $stack->requests()[1]->getUri());
    }

    public function testTheUrlOfTheReturnIsUsedAsIsWhenItIsOnTheDownloadOrigin(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<a/>', ['Content-Type' => 'application/xml']);

        self::download($stack, self::reference('F1', null, 'https://TELECHARGEMENT.dpae-edi.urssaf.fr:443/other/path/F1/9?v=2'));

        $uri = $stack->requests()[1]->getUri();
        static::assertSame('telechargement.dpae-edi.urssaf.fr', $uri->getHost());
        static::assertSame('/other/path/F1/9', $uri->getPath());
        static::assertSame('v=2', $uri->getQuery());
    }

    public function testTheUrlWinsOverTheIdentifiersOnTheDownloadOrigin(): void
    {
        $endpoints = ApiConfiguration::defaults()->endpoints()->withBaseUri(DpaeService::Download, 'http://mock.test:1080/dl');
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withEndpoints($endpoints), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, '<a/>', ['Content-Type' => 'application/xml']);

        self::download($stack, self::reference('F1', '1', 'http://mock.test:1080/elsewhere/F1/1'));

        static::assertSame('http://mock.test:1080/elsewhere/F1/1', (string) $stack->requests()[1]->getUri());
    }
}
