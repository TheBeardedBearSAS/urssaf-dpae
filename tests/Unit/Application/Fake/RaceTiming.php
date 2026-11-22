<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

/**
 * When the other worker of a {@see RacingDepositRepository} strikes.
 */
enum RaceTiming
{
    /**
     * Right before the next save, whatever came before.
     */
    case NextSave;

    /**
     * Right before the first save that follows a claim (the answer of a send).
     */
    case AfterClaim;

    /**
     * Right before every save that follows a claim: the deposit never settles.
     */
    case EveryTimeAfterClaim;
}
