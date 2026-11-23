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
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\WebUri;

final class WebUriTest extends TestCase
{
    #[DataProvider('provideOrigins')]
    public function testOriginOfNormalisesSchemeHostAndEffectivePort(string $url, ?string $expected): void
    {
        static::assertSame($expected, WebUri::originOf($url));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideOrigins(): iterable
    {
        yield 'https default port' => ['https://telechargement.dpae-edi.urssaf.fr/x', 'https://telechargement.dpae-edi.urssaf.fr:443'];
        yield 'http default port' => ['http://host.test/x', 'http://host.test:80'];
        yield 'explicit port' => ['https://host.test:8443/x?y=1#z', 'https://host.test:8443'];
        yield 'upper case' => ['HTTPS://Host.TEST:443/x', 'https://host.test:443'];
        yield 'no path' => ['https://host.test', 'https://host.test:443'];
        yield 'ipv6' => ['https://[::1]:9/x', 'https://[::1]:9'];
        yield 'credentials' => ['https://good.test@evil.test/x', null];
        yield 'empty credentials' => ['https://@good.test/x', null];
        yield 'password only' => ['https://:secret@good.test/x', null];
        yield 'relative' => ['/telecharger-retour/1.0/F1/1', null];
        yield 'protocol relative' => ['//good.test/x', null];
        yield 'other scheme' => ['ftp://good.test/x', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'no host' => ['https:///x', null];
        yield 'backslash' => ['https://evil.test\\@good.test/', null];
        yield 'whitespace' => ['https://good.test/a b', null];
        yield 'port zero' => ['https://good.test:0/x', null];
        yield 'empty' => ['', null];
    }
}
