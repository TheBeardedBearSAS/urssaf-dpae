<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DepositHydrator;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;

/**
 * A property added to the aggregate must be stored and read back: the hydrator lists them by hand.
 */
final class DepositHydratorTest extends TestCase
{
    public function testItHydratesEveryPropertyOfTheAggregateButItsPendingEvents(): void
    {
        $properties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            array_filter((new ReflectionClass(Deposit::class))->getProperties(), static fn(ReflectionProperty $property): bool => !$property->isStatic()),
        );
        $properties = array_values(array_diff($properties, ['events']));
        $hydrated = DepositHydrator::PROPERTIES;
        sort($properties);
        sort($hydrated);

        static::assertSame($properties, $hydrated);
    }
}
