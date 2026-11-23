<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use DateInterval;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Splits a period into the consecutive two-date ranges one listing may cover (ADR 0006): at most `WorkflowPolicy::pollingMaxRange()`
 * each, or the whole period when URSSAF's limit is unknown. Shared by the synchronisation and the reconciliation.
 */
final class ListingWindows
{
    /**
     * @return list<array{Instant, Instant}> `[from, to]` pairs covering `[$from, $until]`; none when `$from` is not before `$until`
     */
    public static function split(Instant $from, Instant $until, ?DateInterval $maxRange): array
    {
        if (!$from->isBefore($until)) {
            return [];
        }
        if (!$maxRange instanceof DateInterval) {
            return [[$from, $until]];
        }

        $windows = [];
        while ($from->isBefore($until)) {
            $to = Instants::plus($from, $maxRange);
            if ($to->isAfter($until)) {
                $to = $until;
            }
            $windows[] = [$from, $to];
            $from = $to;
        }

        return $windows;
    }
}
