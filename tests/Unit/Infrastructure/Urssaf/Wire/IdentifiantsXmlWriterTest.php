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

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

final class IdentifiantsXmlWriterTest extends TestCase
{
    private static function credentials(string $nom, string $prenom, string $motdepasse, ServiceCode $service): DeclarantCredentials
    {
        return DeclarantCredentials::of(Siret::fromString('99999999999999'), $nom, $prenom, $motdepasse, $service);
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmpty(string $xml): string
    {
        if ($xml === '') {
            static::fail('The writer returned an empty body.');
        }

        return $xml;
    }

    public function testItWritesTheInferredShape(): void
    {
        $writer = IdentifiantsXmlWriter::utf8();
        $xml = $writer->write(self::credentials('Dupond', 'Alain', 'motdepasse1', ServiceCode::direct()));

        static::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<identifiants><siret>99999999999999</siret><nom>Dupond</nom><prenom>Alain</prenom>'
            . "<motdepasse>motdepasse1</motdepasse><service>25</service></identifiants>\n",
            $xml,
        );
    }

    public function testTheServiceIsSentAsIs(): void
    {
        $xml = IdentifiantsXmlWriter::utf8()->write(self::credentials('Dupond', 'Alain', 'motdepasse1', ServiceCode::fromInt(97)));

        static::assertStringContainsString('<service>97</service>', $xml);
        static::assertStringContainsString('<service>98</service>', IdentifiantsXmlWriter::utf8()->write(self::credentials('Dupond', 'Alain', 'motdepasse1', ServiceCode::concentrator())));
    }

    public function testTextIsEscapedAndRoundTrips(): void
    {
        $motdepasse = 'p<a>&"s\'s]]>w0rd';
        $xml = IdentifiantsXmlWriter::utf8()->write(self::credentials("D'Artagnan & <Fils>", 'Anne-Élise', $motdepasse, ServiceCode::direct()));

        static::assertStringNotContainsString($motdepasse, $xml);
        static::assertStringContainsString('&lt;a&gt;&amp;', $xml);
        $document = new DOMDocument();
        static::assertTrue($document->loadXML(self::nonEmpty($xml)));
        static::assertSame($motdepasse, $document->getElementsByTagName('motdepasse')->item(0)?->textContent);
        static::assertSame("D'Artagnan & <Fils>", $document->getElementsByTagName('nom')->item(0)?->textContent);
        static::assertSame('Anne-Élise', $document->getElementsByTagName('prenom')->item(0)?->textContent);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideCharactersXmlCannotCarry(): iterable
    {
        yield 'control character in the name' => ["Du\x01pond", 'Alain', 'motdepasse1', 'nom'];
        yield 'NUL in the first name' => ['Dupond', "Al\0ain", 'motdepasse1', 'prenom'];
        yield 'form feed in the password' => ['Dupond', 'Alain', "mot\x0Cdepasse1", 'motdepasse'];
        yield 'non character in the password' => ['Dupond', 'Alain', "motdepasse\u{FFFE}", 'motdepasse'];
        yield 'unit separator in the password' => ['Dupond', 'Alain', "motdepasse\x1F", 'motdepasse'];
    }

    #[DataProvider('provideCharactersXmlCannotCarry')]
    public function testACharacterXmlCannotCarryIsRefusedWithoutEchoingTheValue(string $nom, string $prenom, string $motdepasse, string $field): void
    {
        try {
            IdentifiantsXmlWriter::utf8()->write(self::credentials($nom, $prenom, $motdepasse, ServiceCode::direct()));
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('authentication.invalid_character', $exception->violationCode());
            static::assertSame(['field' => $field], $exception->params());
            static::assertStringNotContainsString($motdepasse, $exception->getMessage());
            static::assertStringNotContainsString($nom, $exception->getMessage());
        }
    }

    public function testWhitespaceDelAndAstralCharactersAreAccepted(): void
    {
        $motdepasse = "tab\tnl\ncr\r\u{1F512}\u{7F}x";
        $xml = IdentifiantsXmlWriter::utf8()->write(self::credentials('Dupond', 'Alain', $motdepasse, ServiceCode::direct()));

        $document = new DOMDocument();
        static::assertTrue($document->loadXML(self::nonEmpty($xml)));
        static::assertSame($motdepasse, $document->getElementsByTagName('motdepasse')->item(0)?->textContent);
    }
}
