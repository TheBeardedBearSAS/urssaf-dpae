<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory;

use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;

/**
 * The order the reconciliation reads deposits in: the oldest claim first, the id breaking ties.
 */
final class ClaimOrder
{
    /**
     * @return list<Deposit> at most \`$limit\` deposits
     */
    public static function oldest(int $limit, Deposit ...$deposits): array
    {
        usort(
            $deposits,
            static fn(Deposit $left, Deposit $right): int => [$left->claimedAt()->toIso8601(), $left->id()->value()] <=> [$right->claimedAt()->toIso8601(), $right->id()->value()],
        );

        return \array_slice($deposits, 0, $limit);
    }
}
