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

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationRules;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationVocabulary;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\DpaeFlowBuilder;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleContract;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployee;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployer;

final class DpaeFlowBuilderEncodingTest extends TestCase
{
    private static function load(string $bytes): DOMDocument
    {
        static::assertNotSame('', $bytes);
        $document = new DOMDocument();
        static::assertTrue($document->loadXML($bytes, \LIBXML_NONET));

        return $document;
    }

    public function testTheFlowIsWrittenInIso88591(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with(['street' => 'Rue de l\'église']),
            ExampleEmployee::with(['surname' => 'Éloïse', 'sexCode' => 'ÿ']),
            ExampleContract::with([]),
            DeclarationRules::of("/^[\\p{L}' .&-]+\$/uD"),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertStringContainsString('encoding="ISO-8859-1"', $flow->bytes());
        static::assertStringContainsString("Rue de l'\xE9glise<", $flow->bytes());
        static::assertStringContainsString(">\xFF<", $flow->bytes());
        static::assertStringContainsString(">\xC9lo\xEFse<", $flow->bytes());
        static::assertFalse(mb_check_encoding($flow->bytes(), 'UTF-8'));
        $surname = self::load($flow->bytes())->getElementsByTagNameNS(
            'http://www.repxml.org/Person_Identity',
            'FR_PersonIdentity.Surname.Text',
        );
        static::assertSame('Éloïse', $surname->item(0)?->textContent);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEscapes(): iterable
    {
        yield 'ampersand' => ['A&B', 'A&amp;B'];
        yield 'less than' => ['A<B', 'A&lt;B'];
        yield 'greater than' => ['A>B', 'A&gt;B'];
        yield 'quotes are legal in text' => ['A"B\'C', 'A"B\'C'];
        yield 'entity lookalike' => ['&amp;', '&amp;amp;'];
    }

    #[DataProvider('provideEscapes')]
    public function testMarkupCharactersAreEscaped(string $value, string $written): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with([]),
            ExampleContract::with(['healthService' => $value]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertStringContainsString('<FR_Contract.HealthService.Text>' . $written . '</FR_Contract.HealthService.Text>', $flow->bytes());
        $service = self::load($flow->bytes())->getElementsByTagName('FR_Contract.HealthService.Text');
        static::assertSame($value, $service->item(0)?->textContent);
    }

    public function testEveryValueThatIso88591CannotHoldIsReported(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with(['sexCode' => "\xFF", 'birthDepartment' => "7\u{20AC}"]),
            ExampleContract::with(['healthService' => "38\x07"]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        try {
            ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            static::assertSame(
                [
                    'employee.sexCode declaration.value_not_encodable ',
                    'employee.birthDepartment declaration.value_not_encodable ',
                    'contract.healthService declaration.value_not_encodable ',
                ],
                ExampleDeclaration::errors($exception->result()),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideUnencodableConfigurations(): iterable
    {
        yield 'indicator' => ["\u{20AC}", 'CDI', 'testIndicator'];
        yield 'contract type' => ['120', "\u{20AC}", 'contract.type'];
    }

    #[DataProvider('provideUnencodableConfigurations')]
    public function testConfiguredWireValuesAreChecked(string $real, string $cdi, string $path): void
    {
        $builder = new DpaeFlowBuilder(TestIndicators::of($real, 'TST'), DeclarationVocabulary::of('CDD', $cdi, 'CTT'));

        try {
            $builder->build(ExampleDeclaration::declaration(), DeclarationMode::Real);
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            static::assertSame([$path . ' declaration.value_not_encodable '], ExampleDeclaration::errors($exception->result()));
        }
    }

    public function testTheLastEncodableCharactersAreAccepted(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with(['sexCode' => " ~\u{00A0}\u{00FF}"]),
            ExampleContract::with([]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertStringContainsString("> ~\xA0\xFF<", $flow->bytes());
    }

    public function testTheHireTimeAndDatesUseTheirOwnFormat(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with(['birthDate' => new DateTimeImmutable('1999-02-03 04:05:06')]),
            ExampleContract::with(['start' => new DateTimeImmutable('2015-07-09 08:09:10')]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertStringContainsString('<rxpers:FR_Birth.Date>1999-02-03</rxpers:FR_Birth.Date>', $flow->bytes());
        static::assertStringContainsString('<FR_Contract.StartContract.Date>2015-07-09</FR_Contract.StartContract.Date>', $flow->bytes());
        static::assertStringContainsString('<FR_Contract.StartContract.Time>08:09:10</FR_Contract.StartContract.Time>', $flow->bytes());
    }
}
