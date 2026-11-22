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
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;

final class ServiceEndpointsDefaultsTest extends TestCase
{
    public function testTheDefaultsAreTheHostsOfTheAddressingTable(): void
    {
        $endpoints = ServiceEndpoints::defaults();
        $direct = ServiceCode::direct();

        static::assertSame('https://mon.urssaf.fr', $endpoints->baseUri(DpaeService::Authentication, $direct));
        static::assertSame('https://depot.dpae-edi.urssaf.fr', $endpoints->baseUri(DpaeService::Deposit, $direct));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr', $endpoints->baseUri(DpaeService::Consultation, $direct));
        static::assertSame('https://telechargement.dpae-edi.urssaf.fr', $endpoints->baseUri(DpaeService::Download, $direct));
        static::assertSame(8443, $endpoints->concentratorPort());
    }

    public function testAConcentratorGetsThePortOnTheDefaultHostsExceptAuthentication(): void
    {
        $endpoints = ServiceEndpoints::defaults();
        $concentrator = ServiceCode::concentrator();

        static::assertSame('https://mon.urssaf.fr', $endpoints->baseUri(DpaeService::Authentication, $concentrator));
        static::assertSame('https://depot.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Deposit, $concentrator));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Consultation, $concentrator));
        static::assertSame('https://telechargement.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Download, $concentrator));
    }

    public function testAnUnknownServiceCodeGetsNoPort(): void
    {
        static::assertSame(
            'https://depot.dpae-edi.urssaf.fr',
            ServiceEndpoints::defaults()->baseUri(DpaeService::Deposit, ServiceCode::fromInt(97)),
        );
    }

    public function testTheConcentratorPortIsConfigurable(): void
    {
        $endpoints = ServiceEndpoints::defaults()->withConcentratorPort(9443);

        static::assertSame(9443, $endpoints->concentratorPort());
        static::assertSame('https://depot.dpae-edi.urssaf.fr:9443', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::concentrator()));
        static::assertSame(1, ServiceEndpoints::defaults()->withConcentratorPort(1)->concentratorPort());
        static::assertSame(65_535, ServiceEndpoints::defaults()->withConcentratorPort(65_535)->concentratorPort());
    }

    #[DataProvider('provideInvalidPorts')]
    public function testItRefusesAPortOutOfRange(int $port): void
    {
        try {
            ServiceEndpoints::defaults()->withConcentratorPort($port);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('concentrator_port.out_of_range', $e->violationCode());
            static::assertSame(['min' => 1, 'max' => 65_535], $e->params());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidPorts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [65_536];
    }
}
