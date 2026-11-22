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

use ArrayObject;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

final class IdentifiantsXmlWriterEncodingTest extends TestCase
{
    private static function credentials(string $nom, string $prenom, string $motdepasse, ServiceCode $service): DeclarantCredentials
    {
        return DeclarantCredentials::of(Siret::fromString('99999999999999'), $nom, $prenom, $motdepasse, $service);
    }

    public function testTheEncodingIsConfigurable(): void
    {
        $xml = IdentifiantsXmlWriter::withEncoding('ISO-8859-1')->write(self::credentials('Dupond', "Zo\u{E9}", 'mot€depasse', ServiceCode::direct()));

        static::assertStringStartsWith("<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n", $xml);
        static::assertStringContainsString("<prenom>Zo\xE9</prenom>", $xml);
        static::assertStringContainsString('<motdepasse>mot&#8364;depasse</motdepasse>', $xml);
        static::assertStringStartsWith(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n",
            IdentifiantsXmlWriter::withEncoding('UTF-8')->write(self::credentials('Dupond', 'Alain', 'motdepasse1', ServiceCode::direct())),
        );
    }

    public function testAnUnknownEncodingIsRefusedAtCreation(): void
    {
        try {
            IdentifiantsXmlWriter::withEncoding('NOT-AN-ENCODING');
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('authentication_encoding.invalid_format', $exception->violationCode());
        }
    }

    public function testABlankEncodingIsRefusedAtCreation(): void
    {
        try {
            IdentifiantsXmlWriter::withEncoding(' ');
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('authentication_encoding.empty', $exception->violationCode());
        }
    }

    public function testProbingTheEncodingLeavesTheErrorHandlerAlone(): void
    {
        error_clear_last();
        $received = new ArrayObject();
        set_error_handler(static function (int $level, string $message) use ($received): bool {
            $received->append($message);

            return true;
        });
        try {
            try {
                IdentifiantsXmlWriter::withEncoding('NOT-AN-ENCODING');
            } catch (InvalidValue $exception) {
                static::assertSame('authentication_encoding.invalid_format', $exception->violationCode());
            }
            static::assertSame([], $received->getArrayCopy());
            static::assertNull(error_get_last());
            trigger_error('after', \E_USER_WARNING);
            static::assertSame(['after'], $received->getArrayCopy());
        } finally {
            restore_error_handler();
        }
    }
}
