<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Deposit;

use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait DepositFixtures
{
    private const T0 = '2022-02-01T10:00:00Z';
    private const T1 = '2022-02-01T10:01:00Z';
    private const T2 = '2022-02-01T10:02:00Z';

    private static function deposit(): Deposit
    {
        return Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T0);
    }

    private static function depositing(): Deposit
    {
        $deposit = self::deposit();
        $deposit->startDeposit(Samples::at(self::T1), 3);

        return $deposit;
    }
}
