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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;

final class ServiceEndpointsDownloadOriginTest extends TestCase
{
    #[DataProvider('provideDownloadUrls')]
    public function testOnlyTheConfiguredDownloadOriginIsTrusted(string $url, bool $expected): void
    {
        static::assertSame($expected, ServiceEndpoints::defaults()->isOnDownloadOrigin($url, ServiceCode::direct()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideDownloadUrls(): iterable
    {
        yield 'same origin' => ['https://telechargement.dpae-edi.urssaf.fr/telecharger-retour/1.0/F1/1', true];
        yield 'same origin with query' => ['https://telechargement.dpae-edi.urssaf.fr/x?token=1', true];
        yield 'upper case host' => ['https://TELECHARGEMENT.dpae-edi.urssaf.fr/x', true];
        yield 'explicit 443' => ['https://telechargement.dpae-edi.urssaf.fr:443/x', true];
        yield 'another port' => ['https://telechargement.dpae-edi.urssaf.fr:8443/x', false];
        yield 'http downgrade' => ['http://telechargement.dpae-edi.urssaf.fr/x', false];
        yield 'another host' => ['https://evil.test/x', false];
        yield 'suffix host' => ['https://telechargement.dpae-edi.urssaf.fr.evil.test/x', false];
        yield 'prefix host' => ['https://evil-telechargement.dpae-edi.urssaf.fr/x', false];
        yield 'sibling host' => ['https://depot.dpae-edi.urssaf.fr/x', false];
        yield 'userinfo trick' => ['https://telechargement.dpae-edi.urssaf.fr@evil.test/x', false];
        yield 'userinfo on the good host' => ['https://evil.test@telechargement.dpae-edi.urssaf.fr/x', false];
        yield 'backslash trick' => ['https://evil.test\\@telechargement.dpae-edi.urssaf.fr/x', false];
        yield 'relative' => ['/telecharger-retour/1.0/F1/1', false];
        yield 'unparsable' => ['https://', false];
    }

    public function testAConcentratorTrustsTheDownloadOriginWithItsPortOnly(): void
    {
        $endpoints = ServiceEndpoints::defaults();
        $concentrator = ServiceCode::concentrator();

        static::assertTrue($endpoints->isOnDownloadOrigin('https://telechargement.dpae-edi.urssaf.fr:8443/x', $concentrator));
        static::assertFalse($endpoints->isOnDownloadOrigin('https://telechargement.dpae-edi.urssaf.fr/x', $concentrator));
    }

    public function testAnOverriddenDownloadOriginIsTheOnlyTrustedOne(): void
    {
        $endpoints = ServiceEndpoints::defaults()->withBaseUri(DpaeService::Download, 'http://Mock.test:1080/prefix');

        static::assertTrue($endpoints->isOnDownloadOrigin('http://mock.test:1080/anything', ServiceCode::concentrator()));
        static::assertFalse($endpoints->isOnDownloadOrigin('https://telechargement.dpae-edi.urssaf.fr/x', ServiceCode::direct()));
    }
}
