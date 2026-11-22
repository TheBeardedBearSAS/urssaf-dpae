<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Command;

use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait ReconciliationFixtures
{
    /**
     * With the default policy (skew 5 minutes, window 1 hour, stale after 15 minutes, review after 6 hours) and a
     * deposit claimed at 10:30, the publication window is [10:25:00, 11:30:00], closed at the fixed clock (12:00).
     */
    public const CLAIMED = '2022-02-01T10:30:00Z';

    private static function command(): ReconcileUnknownDeposits
    {
        return new ReconcileUnknownDeposits(Samples::tenant('tenant-1'));
    }

    private static function sent(Harness $harness, string $id, string $tenant, string $variant, string $claimedAt): Deposit
    {
        $deposit = Samples::deposit($id, $tenant, 'ref-' . $id, $variant, $claimedAt);
        $harness->deposits->save($deposit);
        $harness->deposits->claim($deposit->id(), Samples::at($claimedAt), 3);

        return $harness->deposits->get($deposit->id());
    }

    private static function unknown(Harness $harness, string $id, string $variant, string $claimedAt): Deposit
    {
        $deposit = self::sent($harness, $id, 'tenant-1', $variant, $claimedAt);
        $deposit->markOutcomeUnknown('deposit.outcome_unknown', Samples::at($claimedAt));
        $deposit->releaseEvents();
        $harness->deposits->save($deposit);

        return $deposit;
    }

    private static function nature10(string $flow, string $status, ?string $publishedAt): ReturnReference
    {
        return SampleReturns::listed($flow, 10, $status, $publishedAt, null);
    }
}
