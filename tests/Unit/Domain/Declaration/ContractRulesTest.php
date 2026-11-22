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

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

final class ContractRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideStarts(): iterable
    {
        yield 'today' => ['2026-11-22 08:00:00', []];
        yield 'exactly one year before, last second' => ['2025-11-22 23:59:59', []];
        yield 'exactly one year before, midnight' => ['2025-11-22 00:00:00', []];
        yield 'one day before the lower bound' => ['2025-11-21 23:59:59', ['contract.start_too_early']];
        yield 'exactly one year after, midnight' => ['2027-11-22 00:00:00', []];
        yield 'exactly one year after, last second' => ['2027-11-22 23:59:59', []];
        yield 'one day after the upper bound' => ['2027-11-23 00:00:00', ['contract.start_too_late']];
        yield 'far in the past' => ['2015-07-17 12:30:00', ['contract.start_too_early']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('provideStarts')]
    public function testHireDateIsWithinOneYearOfToday(string $start, array $codes): void
    {
        $errors = ExampleContract::with(['start' => new DateTimeImmutable($start), 'endDate' => null])
            ->validate(ExampleDeclaration::now())
            ->errors();

        static::assertSame($codes, array_map(static fn(Violation $violation): string => $violation->code(), $errors));
        foreach ($errors as $error) {
            static::assertSame('start', $error->path());
            static::assertSame(['normCode' => '35'], $error->params());
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function provideLeapDayStarts(): iterable
    {
        yield 'leap day, same day one year before' => ['2024-02-29', '2023-02-28 10:00:00', []];
        yield 'leap day, a day before the lower bound' => ['2024-02-29', '2023-02-27 10:00:00', ['contract.start_too_early']];
        yield 'leap day, 1 March is inside' => ['2024-02-29', '2023-03-01 10:00:00', []];
        yield 'leap day, same day one year after' => ['2024-02-29', '2025-02-28 23:00:00', []];
        yield 'leap day, a day after the upper bound' => ['2024-02-29', '2025-03-01 00:00:00', ['contract.start_too_late']];
        yield 'day before a leap year, upper bound' => ['2023-02-28', '2024-02-28 12:00:00', []];
        yield 'day before a leap year, a day after' => ['2023-02-28', '2024-02-29 00:00:00', ['contract.start_too_late']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('provideLeapDayStarts')]
    public function testTheYearWindowIsTheSameCalendarDay(string $today, string $start, array $codes): void
    {
        $errors = ExampleContract::with(['start' => new DateTimeImmutable($start), 'endDate' => null])
            ->validate(new DateTimeImmutable($today . ' 08:00:00'))
            ->errors();

        static::assertSame($codes, array_map(static fn(Violation $violation): string => $violation->code(), $errors));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideEnds(): iterable
    {
        yield 'a day after' => ['2026-11-23', []];
        yield 'same day' => ['2026-11-22', []];
        yield 'a day before' => ['2026-11-21', ['endDate contract.end_before_start 46']];
        yield 'a year before' => ['2025-11-22', ['endDate contract.end_before_start 46']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideEnds')]
    public function testEndDateIsNotBeforeTheHireDate(string $end, array $expected): void
    {
        $contract = ExampleContract::with([
            'start' => new DateTimeImmutable('2026-11-22 18:00:00'),
            'endDate' => new DateTimeImmutable($end),
        ]);

        static::assertSame(
            $expected,
            ExampleDeclaration::errors($contract->validate(ExampleDeclaration::now())),
        );
    }

    /**
     * @return iterable<string, array{ContractType, string|null, list<string>}>
     */
    public static function provideHealthServices(): iterable
    {
        yield 'cdi with a service' => [ContractType::Cdi, '387', []];
        yield 'cdi internal medicine' => [ContractType::Cdi, 'MT01', []];
        yield 'cdi missing' => [ContractType::Cdi, null, ['healthService contract.health_service_empty 50']];
        yield 'cdi empty' => [ContractType::Cdi, '', ['healthService contract.health_service_empty 50']];
        yield 'cdi blank' => [ContractType::Cdi, '  ', ['healthService contract.health_service_empty 50']];
        yield 'cdd missing' => [ContractType::Cdd, null, ['healthService contract.health_service_empty 50']];
        yield 'cdd with a service' => [ContractType::Cdd, 'MT999', []];
        yield 'ctt missing' => [ContractType::Ctt, null, []];
        yield 'ctt empty' => [ContractType::Ctt, '', []];
        yield 'ctt with a service' => [ContractType::Ctt, '387', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideHealthServices')]
    public function testHealthServiceIsMandatoryExceptForACtt(ContractType $type, ?string $service, array $expected): void
    {
        $contract = ExampleContract::with([
            'type' => $type,
            'healthService' => $service,
            'endDate' => null,
        ]);

        static::assertSame(
            $expected,
            ExampleDeclaration::errors($contract->validate(new DateTimeImmutable('2015-07-17'))),
        );
    }
}
