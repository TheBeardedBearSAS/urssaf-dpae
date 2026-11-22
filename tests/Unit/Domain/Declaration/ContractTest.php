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
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;
use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;

final class ContractTest extends TestCase
{
    public function testGettersAndTheValidExample(): void
    {
        $contract = ExampleContract::with([]);

        static::assertSame('2015-07-17 12:30:00', $contract->start()->format('Y-m-d H:i:s'));
        $endDate = $contract->endDate();
        static::assertInstanceOf(DateTimeImmutable::class, $endDate);
        static::assertSame('2015-09-15', $endDate->format('Y-m-d'));
        static::assertSame(ContractType::Cdi, $contract->type());
        static::assertSame('387', $contract->healthService());
        static::assertSame([], ExampleDeclaration::errors($contract->validate(new DateTimeImmutable('2015-07-17'))));
    }

    public function testTheEndDateIsOptional(): void
    {
        $contract = ExampleContract::with(['endDate' => null]);

        static::assertNull($contract->endDate());
        static::assertSame([], ExampleDeclaration::errors($contract->validate(new DateTimeImmutable('2015-07-17'))));
    }

    public function testEveryViolationIsCollectedInTheOrderOfTheChecks(): void
    {
        $contract = ExampleContract::with([
            'start' => new DateTimeImmutable('2020-01-01'),
            'endDate' => new DateTimeImmutable('2019-01-01'),
            'healthService' => null,
        ]);

        static::assertSame(
            [
                'start contract.start_too_early 35',
                'endDate contract.end_before_start 46',
                'healthService contract.health_service_empty 50',
            ],
            ExampleDeclaration::errors($contract->validate(ExampleDeclaration::now())),
        );
    }

    public function testDebugInfoShowsTheTypeOnly(): void
    {
        static::assertSame(['type' => ContractType::Cdi], ExampleContract::with([])->__debugInfo());
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(PayloadNotSerializable::class);

        serialize(ExampleContract::with([]));
    }

    public function testItCannotBeUnserialized(): void
    {
        $contract = ExampleContract::with([]);

        $this->expectException(PayloadNotSerializable::class);

        $contract->__unserialize([]);
    }
}
