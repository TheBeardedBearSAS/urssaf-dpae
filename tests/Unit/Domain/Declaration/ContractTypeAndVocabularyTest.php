<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationRules;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationVocabulary;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class ContractTypeAndVocabularyTest extends TestCase
{
    public function testContractTypesAreBackedByTheNormCodes(): void
    {
        static::assertSame(1, ContractType::Cdd->value);
        static::assertSame(2, ContractType::Cdi->value);
        static::assertSame(3, ContractType::Ctt->value);
    }

    public function testDefaultVocabularyKeepsTheAttestedCdiValue(): void
    {
        $vocabulary = DeclarationVocabulary::defaults();

        static::assertSame('CDD', $vocabulary->contractType(ContractType::Cdd));
        static::assertSame('CDI', $vocabulary->contractType(ContractType::Cdi));
        static::assertSame('CTT', $vocabulary->contractType(ContractType::Ctt));
    }

    public function testNormCodesVocabulary(): void
    {
        $vocabulary = DeclarationVocabulary::normCodes();

        static::assertSame('1', $vocabulary->contractType(ContractType::Cdd));
        static::assertSame('2', $vocabulary->contractType(ContractType::Cdi));
        static::assertSame('3', $vocabulary->contractType(ContractType::Ctt));
    }

    public function testCustomVocabulary(): void
    {
        $vocabulary = DeclarationVocabulary::of('X1', 'X2', 'X3');

        static::assertSame('X1', $vocabulary->contractType(ContractType::Cdd));
        static::assertSame('X2', $vocabulary->contractType(ContractType::Cdi));
        static::assertSame('X3', $vocabulary->contractType(ContractType::Ctt));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideRefusedVocabularies(): iterable
    {
        yield 'blank cdd' => [' ', 'B', 'C', 'contract_type_cdd.empty'];
        yield 'empty cdd' => ['', 'B', 'C', 'contract_type_cdd.empty'];
        yield 'blank cdi' => ['A', ' ', 'C', 'contract_type_cdi.empty'];
        yield 'empty cdi' => ['A', '', 'C', 'contract_type_cdi.empty'];
        yield 'blank ctt' => ['A', 'B', "\t", 'contract_type_ctt.empty'];
        yield 'empty ctt' => ['A', 'B', '', 'contract_type_ctt.empty'];
        yield 'cdd = cdi' => ['A', 'A', 'C', 'declaration_vocabulary.not_distinct'];
        yield 'cdd = ctt' => ['A', 'B', 'A', 'declaration_vocabulary.not_distinct'];
        yield 'cdi = ctt' => ['A', 'B', 'B', 'declaration_vocabulary.not_distinct'];
    }

    #[DataProvider('provideRefusedVocabularies')]
    public function testRefusedVocabularies(string $cdd, string $cdi, string $ctt, string $code): void
    {
        try {
            DeclarationVocabulary::of($cdd, $cdi, $ctt);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
        }
    }

    public function testDefaultRulesAreTheStrictNormSet(): void
    {
        static::assertSame("/^[A-Z' .&-]+\$/D", DeclarationRules::defaults()->employeeNamePattern());
        static::assertSame(
            DeclarationRules::DEFAULT_EMPLOYEE_NAME_PATTERN,
            DeclarationRules::defaults()->employeeNamePattern(),
        );
        static::assertMatchesRegularExpression(DeclarationRules::defaults()->employeeNamePattern(), 'ALAIN');
        static::assertDoesNotMatchRegularExpression(DeclarationRules::defaults()->employeeNamePattern(), 'Alain');
    }

    public function testCustomNamePattern(): void
    {
        static::assertSame('/^[A-Z]+$/D', DeclarationRules::of('/^[A-Z]+$/D')->employeeNamePattern());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        yield 'no delimiters' => ['abc'];
        yield 'unclosed group' => ['/(/'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideInvalidPatterns')]
    public function testInvalidPatternIsRefusedWithoutLeakingAWarning(string $pattern): void
    {
        $warnings = [];
        error_clear_last();
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            DeclarationRules::of($pattern);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('employee_name_pattern.invalid_format', $exception->violationCode());
        } finally {
            // The handler of the test is back in place: the one of the rules was removed.
            trigger_error('after', \E_USER_NOTICE);
            restore_error_handler();
        }

        static::assertSame(['after'], $warnings);
        static::assertNull(error_get_last());
    }
}
