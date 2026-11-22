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
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\RetoursXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class RetoursXmlReaderRefusalTest extends TestCase
{
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

    public function testAnUnreadableBodyIsRefused(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        self::readXml('<html>');
    }

    public function testAnErrorDocumentIsNotAnEmptyList(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);
        $this->expectExceptionMessage('the list answer has no retours or flux element');

        self::readXml('<erreur><code>1</code></erreur>');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnusableReturns(): iterable
    {
        yield 'flux without id' => ['<retours><flux><retour>' . self::MINIMAL . '</retour></flux></retours>', 'a flux has no id'];
        yield 'flux with a blank id' => ['<retours><flux><id> </id><retour>' . self::MINIMAL . '</retour></flux></retours>', 'a flux has no id'];
        yield 'flux with a too long id' => ['<retours><flux><id>' . str_repeat('x', 51) . '</id><retour>' . self::MINIMAL . '</retour></flux></retours>', 'a flux has an unusable id'];
        yield 'no publication' => [self::wrap('<production>20220130082500</production><nature>10</nature><statut>OK</statut>'), 'a retour has no publication'];
        yield 'no production' => [self::wrap('<publication>20220130083000</publication><nature>10</nature><statut>OK</statut>'), 'a retour has no production'];
        yield 'no nature' => [self::wrap('<publication>20220130083000</publication><production>20220130082500</production><statut>OK</statut>'), 'a retour has no nature'];
        yield 'no statut' => [self::wrap('<publication>20220130083000</publication><production>20220130082500</production><nature>10</nature>'), 'a retour has no statut'];
        yield 'nature is text' => [self::wrap(str_replace('<nature>10', '<nature>dix', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature with a plus sign' => [self::wrap(str_replace('<nature>10', '<nature>+41', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature is decimal' => [self::wrap(str_replace('<nature>10', '<nature>1.5', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature has text around' => [self::wrap(str_replace('<nature>10', '<nature>10x', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature above int32' => [self::wrap(str_replace('<nature>10', '<nature>2147483648', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature below int32' => [self::wrap(str_replace('<nature>10', '<nature>-2147483649', self::MINIMAL)), 'a retour has a nature that is not an integer'];
        yield 'nature far too long' => [self::wrap(str_replace('<nature>10', '<nature>99999999999999999999', self::MINIMAL)), 'a retour has a nature that is not an integer'];
    }

    #[DataProvider('provideUnusableReturns')]
    public function testWhatCannotBeRepresentedIsRefusedNotSkipped(string $xml, string $reason): void
    {
        try {
            self::readXml($xml);
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertSame('Unexpected URSSAF response: ' . $reason . '.', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideNatures(): iterable
    {
        yield 'plain' => ['10', 10];
        yield 'leading zero' => ['010', 10];
        yield 'negative' => ['-5', -5];
        yield 'zero' => ['0', 0];
        yield 'int32 max' => ['2147483647', 2_147_483_647];
        yield 'int32 min' => ['-2147483648', -2_147_483_648];
        yield 'padded' => [' 20 ', 20];
    }

    #[DataProvider('provideNatures')]
    public function testItReadsTheNature(string $value, int $expected): void
    {
        $xml = self::wrap(str_replace('<nature>10', '<nature>' . $value, self::MINIMAL));

        static::assertSame($expected, self::readXml($xml)[0]->natureCode());
    }

    public function testAFluxWithoutAnyRetourNeedsNoId(): void
    {
        static::assertSame([], self::readXml('<retours><flux/><flux><id> </id></flux><flux><id>' . str_repeat('x', 51) . '</id></flux></retours>'));
    }
}
