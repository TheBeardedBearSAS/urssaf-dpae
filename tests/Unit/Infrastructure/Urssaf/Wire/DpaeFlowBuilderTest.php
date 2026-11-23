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
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationVocabulary;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\DpaeFlowBuilder;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleContract;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployee;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployer;

final class DpaeFlowBuilderTest extends TestCase
{
    private const HEAD = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\" ?>\n"
        . '<FR_DUE_Upload xmlns:cct="urn:oasis:names:tc:ubl:corecomponentTypes:1.0:0.70"'
        . ' xmlns:rxdt="http://www.repxml.org/DataTypes"'
        . ' xmlns:rxorg="http://www.repxml.org/Organization"'
        . ' xmlns:rxpadr="http://www.repxml.org/PostalAddress"'
        . ' xmlns:rxpers="http://www.repxml.org/Person_Identity"'
        . ' xmlns:rxphadr="http://www.repxml.org/PhoneAddress"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

    private const EMPLOYER = '<FR_DuesGroup><FR_Employer><FR_EmployerIdentity>'
        . '<rxorg:FR_Organization.SIRET.Identifier>99999999999999</rxorg:FR_Organization.SIRET.Identifier>'
        . '<rxorg:FR_Organization.APE.Code>8110Z</rxorg:FR_Organization.APE.Code>'
        . '</FR_EmployerIdentity>'
        . '<FR_Employer.URSSAF.Code>257</FR_Employer.URSSAF.Code>'
        . '<FR_EmployerAddress>'
        . '<rxpadr:FR_PostalAddress.StreetDesignation.Text>44 rue DES TEST</rxpadr:FR_PostalAddress.StreetDesignation.Text>'
        . '<rxpadr:FR_PostalAddress.Town.Text>CAEN</rxpadr:FR_PostalAddress.Town.Text>'
        . '<rxpadr:FR_PostalAddress.Postal.Code>14000</rxpadr:FR_PostalAddress.Postal.Code>'
        . '</FR_EmployerAddress>';

    private const PHONE = '<FR_EmployerContact><FR_PhoneNumber>'
        . '<rxphadr:FR_PhoneAddress.PhoneNumber.Text>0000000000</rxphadr:FR_PhoneAddress.PhoneNumber.Text>'
        . '</FR_PhoneNumber></FR_EmployerContact>';

    private const IDENTITY_START = '</FR_Employer><FR_EmployeeGroup><FR_Employee><FR_EmployeeIdentity>'
        . '<rxpers:FR_PersonIdentity.Surname.Text>TEST</rxpers:FR_PersonIdentity.Surname.Text>'
        . '<rxpers:FR_PersonIdentity.ChristianName.Text>Alain</rxpers:FR_PersonIdentity.ChristianName.Text>';

    private const SEX = '<rxpers:FR_PersonIdentity.Sex.Code>1</rxpers:FR_PersonIdentity.Sex.Code>';

    private const NIR = '<rxpers:FR_NNI>'
        . '<rxpers:FR_NNI.NIR.Identifier>1000000000000</rxpers:FR_NNI.NIR.Identifier>'
        . '<rxpers:FR_NNI.NIRKey.Text>99</rxpers:FR_NNI.NIRKey.Text>'
        . '</rxpers:FR_NNI>';

    private const BIRTH = '<rxpers:FR_Birth>'
        . '<rxpers:FR_Birth.Date>1970-01-01</rxpers:FR_Birth.Date>'
        . '<rxpers:FR_Birth.Town.Text>PARIS</rxpers:FR_Birth.Town.Text>'
        . '</rxpers:FR_Birth></FR_EmployeeIdentity>'
        . '<FR_EmployeeComplement>'
        . '<FR_EmployeeComplement.Birth_Department.Code>75</FR_EmployeeComplement.Birth_Department.Code>'
        . '</FR_EmployeeComplement></FR_Employee>';

    private const CONTRACT_START = '<FR_Contract>'
        . '<FR_Contract.StartContract.Date>2015-07-17</FR_Contract.StartContract.Date>'
        . '<FR_Contract.StartContract.Time>12:30:00</FR_Contract.StartContract.Time>';

    private const END = '<FR_Contract.EndContract.Date>2015-09-15</FR_Contract.EndContract.Date>';

    private const HEALTH = '<FR_Contract.HealthService.Text>387</FR_Contract.HealthService.Text>';

    private const TAIL = '</FR_Contract></FR_EmployeeGroup></FR_DuesGroup></FR_DUE_Upload>';

    private static function indicator(string $value): string
    {
        return '<FR_DUE_Upload.Test.Indicator>' . $value . '</FR_DUE_Upload.Test.Indicator>';
    }

    private static function canonical(string $xml): string
    {
        static::assertNotSame('', $xml);
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        static::assertTrue($document->loadXML($xml, \LIBXML_NONET));
        $canonical = $document->C14N();
        static::assertIsString($canonical);

        return $canonical;
    }

    private static function fixture(): string
    {
        $bytes = file_get_contents(__DIR__ . '/../../../../Fixtures/wire/deposit_request_minimal.xml');
        static::assertIsString($bytes);

        return $bytes;
    }

    public function testTheOfficialExampleIsReproducedSemantically(): void
    {
        $flow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Real);

        static::assertSame(self::canonical(self::fixture()), self::canonical($flow->bytes()));
        static::assertSame(DeclarationMode::Real, $flow->mode());
    }

    public function testTheExactBytesOfTheFullExample(): void
    {
        $flow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Real);

        static::assertSame(
            self::HEAD . self::indicator('120') . self::EMPLOYER . self::PHONE . self::IDENTITY_START
            . self::SEX . self::NIR . self::BIRTH . self::CONTRACT_START . self::END
            . '<FR_Contract.Nature.Code>CDI</FR_Contract.Nature.Code>' . self::HEALTH . self::TAIL,
            $flow->bytes(),
        );
    }

    public function testOptionalElementsAreOmittedWhenAbsent(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with(['phone' => null, 'apeCode' => '7820Z']),
            ExampleEmployee::with(['sexCode' => null, 'nir' => null]),
            ExampleContract::with(['endDate' => null, 'healthService' => null, 'type' => ContractType::Ctt]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertSame(
            self::HEAD . self::indicator('120')
            . str_replace('8110Z', '7820Z', self::EMPLOYER) . self::IDENTITY_START
            . self::BIRTH . self::CONTRACT_START
            . '<FR_Contract.Nature.Code>CTT</FR_Contract.Nature.Code>' . self::TAIL,
            $flow->bytes(),
        );
    }

    public function testAThirteenCharacterNirHasNoKeyElement(): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with(['nir' => '1000000000000']),
            ExampleContract::with([]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        $flow = ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);

        static::assertStringContainsString(
            '<rxpers:FR_NNI><rxpers:FR_NNI.NIR.Identifier>1000000000000</rxpers:FR_NNI.NIR.Identifier></rxpers:FR_NNI>',
            $flow->bytes(),
        );
        static::assertStringNotContainsString('NIRKey', $flow->bytes());
    }

    public function testTheTestModeWritesTheTestIndicator(): void
    {
        $flow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Test);

        static::assertSame(DeclarationMode::Test, $flow->mode());
        static::assertStringContainsString(self::indicator('TST'), $flow->bytes());
        static::assertStringNotContainsString(self::indicator('120'), $flow->bytes());
    }

    public function testIndicatorsAndVocabularyAreConfigurable(): void
    {
        $builder = new DpaeFlowBuilder(TestIndicators::of('R', 'T'), DeclarationVocabulary::normCodes());

        $real = $builder->build(ExampleDeclaration::declaration(), DeclarationMode::Real);
        $test = $builder->build(ExampleDeclaration::declaration(), DeclarationMode::Test);

        static::assertStringContainsString(self::indicator('R'), $real->bytes());
        static::assertStringContainsString(self::indicator('T'), $test->bytes());
        static::assertStringContainsString('<FR_Contract.Nature.Code>2</FR_Contract.Nature.Code>', $real->bytes());
    }

    public function testTheBuiltFlowPassesTheRawFlowCheck(): void
    {
        $flow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Test);

        $again = DpaeFlow::fromXml($flow->bytes(), DeclarationMode::Test, TestIndicators::defaults());

        static::assertTrue($flow->fingerprint()->equals($again->fingerprint()));
    }
}
