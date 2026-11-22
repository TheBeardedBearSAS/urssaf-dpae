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

final class ServiceEndpointsOverrideTest extends TestCase
{
    public function testAnOverriddenBaseUriIsUsedVerbatimForEveryTenantWithoutThePort(): void
    {
        $endpoints = ServiceEndpoints::defaults()
            ->withBaseUri(DpaeService::Deposit, 'http://mock.test:1080/')
            ->withBaseUri(DpaeService::Authentication, 'https://gateway.test/authentifier_dpae');

        static::assertSame('http://mock.test:1080', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::concentrator()));
        static::assertSame('http://mock.test:1080', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::direct()));
        static::assertSame('https://gateway.test/authentifier_dpae', $endpoints->baseUri(DpaeService::Authentication, ServiceCode::direct()));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Consultation, ServiceCode::concentrator()));
    }

    public function testOverridingTwiceKeepsTheLastValueAndEveryOtherOverride(): void
    {
        $endpoints = ServiceEndpoints::defaults()
            ->withBaseUri(DpaeService::Deposit, 'https://one.test')
            ->withBaseUri(DpaeService::Download, 'https://download.test')
            ->withBaseUri(DpaeService::Deposit, 'https://two.test');
        $direct = ServiceCode::direct();

        static::assertSame('https://two.test', $endpoints->baseUri(DpaeService::Deposit, $direct));
        static::assertSame('https://download.test', $endpoints->baseUri(DpaeService::Download, $direct));
    }

    public function testTheOverridesSurviveAChangeOfPortAndViceVersa(): void
    {
        $endpoints = ServiceEndpoints::defaults()
            ->withBaseUri(DpaeService::Download, 'https://download.test')
            ->withConcentratorPort(9443)
            ->withBaseUri(DpaeService::Deposit, 'https://deposit.test');

        static::assertSame(9443, $endpoints->concentratorPort());
        static::assertSame('https://download.test', $endpoints->baseUri(DpaeService::Download, ServiceCode::concentrator()));
        static::assertSame('https://deposit.test', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::concentrator()));
    }

    public function testAServicePathIsAppendedToTheBaseUri(): void
    {
        $endpoints = ServiceEndpoints::defaults()->withBaseUri(DpaeService::Deposit, 'https://api.urssaf.fr/authentifier_dpae/');

        static::assertSame(
            'https://api.urssaf.fr/authentifier_dpae/deposer-dsn/1.0',
            $endpoints->uri(DpaeService::Deposit, ServiceCode::direct(), '/deposer-dsn/1.0'),
        );
        static::assertSame(
            'https://mon.urssaf.fr/authentifier_dpae',
            ServiceEndpoints::defaults()->uri(DpaeService::Authentication, ServiceCode::direct(), '/authentifier_dpae'),
        );
    }

    #[DataProvider('providePublishedHostsOverHttp')]
    public function testHttpIsRefusedForAPublishedUrssafHost(string $baseUri): void
    {
        try {
            ServiceEndpoints::defaults()->withBaseUri(DpaeService::Deposit, $baseUri);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('base_uri.insecure_scheme', $e->violationCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePublishedHostsOverHttp(): iterable
    {
        yield 'authentication' => ['http://mon.urssaf.fr'];
        yield 'deposit' => ['http://depot.dpae-edi.urssaf.fr'];
        yield 'consultation' => ['http://consultation.dpae-edi.urssaf.fr/x'];
        yield 'download' => ['http://telechargement.dpae-edi.urssaf.fr:8080'];
        yield 'upper case' => ['HTTP://Depot.DPAE-EDI.urssaf.fr'];
        yield 'trailing dot' => ['http://depot.dpae-edi.urssaf.fr./'];
    }

    public function testHttpIsAcceptedForAMockServerAndHttpsForAPublishedHost(): void
    {
        $endpoints = ServiceEndpoints::defaults()
            ->withBaseUri(DpaeService::Deposit, 'http://mock.test:1080')
            ->withBaseUri(DpaeService::Download, 'https://telechargement.dpae-edi.urssaf.fr:8443');

        static::assertSame('http://mock.test:1080', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::direct()));
        static::assertSame('https://telechargement.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Download, ServiceCode::direct()));
    }

    #[DataProvider('provideInvalidBaseUris')]
    public function testItRefusesAnInvalidBaseUri(string $baseUri): void
    {
        try {
            ServiceEndpoints::defaults()->withBaseUri(DpaeService::Deposit, $baseUri);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('base_uri.invalid_format', $e->violationCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidBaseUris(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['depot.test'];
        yield 'relative' => ['/depot'];
        yield 'scheme only' => ['https://'];
        yield 'other scheme' => ['ftp://depot.test'];
        yield 'credentials' => ['https://user:secret@depot.test'];
        yield 'user only' => ['https://user@depot.test'];
        yield 'query' => ['https://depot.test?x=1'];
        yield 'empty query' => ['https://depot.test/?'];
        yield 'fragment' => ['https://depot.test#top'];
        yield 'empty fragment' => ['https://depot.test/#'];
        yield 'space' => ['https://depot.test/a b'];
        yield 'control character' => ["https://depot.test/a\nb"];
        yield 'backslash' => ['https://depot.test\\evil'];
        yield 'port zero' => ['https://depot.test:0'];
        yield 'port too large' => ['https://depot.test:99999'];
    }

    public function testItAcceptsAnUpperCaseSchemeAnIpv6HostAndAPort(): void
    {
        $endpoints = ServiceEndpoints::defaults()
            ->withBaseUri(DpaeService::Deposit, 'HTTP://[::1]:8080/depot')
            ->withBaseUri(DpaeService::Consultation, 'https://consultation.test:1');

        static::assertSame('HTTP://[::1]:8080/depot', $endpoints->baseUri(DpaeService::Deposit, ServiceCode::direct()));
        static::assertSame('https://consultation.test:1', $endpoints->baseUri(DpaeService::Consultation, ServiceCode::direct()));
    }
}
