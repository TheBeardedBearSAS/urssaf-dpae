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

use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Which flux a deposit may be given (ADR 0005), from the windows of every deposit without a flow id.
 */
final class CandidateSelection
{
    /**
     * The candidate of the target when it is the only one in its window and no other deposit's window contains its
     * publication, else null: two deposits that could both have produced it never get it.
     *
     * @param list<ReturnReference> $matching the candidates published in the target's window
     * @param list<ClaimWindow>     $windows  the windows of every claimed deposit of the tenant without a flow id
     */
    public static function sole(ClaimWindow $target, array $matching, array $windows): ?ReturnReference
    {
        if (\count($matching) !== 1) {
            return null;
        }
        foreach ($windows as $other) {
            if (!$other->deposit->id()->equals($target->deposit->id()) && $other->containsPublicationOf($matching[0])) {
                return null;
            }
        }

        return $matching[0];
    }

    /**
     * The candidates no window contains, whose whole neighbourhood was read: with a `$horizon` (the read stopped there), only
     * those published before it.
     *
     * @param list<ReturnReference> $candidates
     * @param list<ClaimWindow>     $windows
     *
     * @return list<FlowId>
     */
    public static function unattached(array $candidates, array $windows, ?Instant $horizon): array
    {
        $flux = [];
        foreach ($candidates as $candidate) {
            if (self::isUnread($candidate, $horizon) || self::inAnyWindow($candidate, $windows)) {
                continue;
            }
            $flux[] = $candidate->flowId();
        }

        return $flux;
    }

    private static function isUnread(ReturnReference $candidate, ?Instant $horizon): bool
    {
        $publishedAt = $candidate->publishedAt();

        return $horizon instanceof Instant && (!$publishedAt instanceof Instant || !$publishedAt->isBefore($horizon));
    }

    /**
     * @param list<ClaimWindow> $windows
     */
    private static function inAnyWindow(ReturnReference $candidate, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($window->containsPublicationOf($candidate)) {
                return true;
            }
        }

        return false;
    }
}
