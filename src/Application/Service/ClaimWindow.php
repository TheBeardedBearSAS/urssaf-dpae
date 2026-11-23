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

use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * The publication window of a deposit that was claimed: `[claimedAt - reconciliationSkew, claimedAt + reconciliationWindow]`,
 * both bounds included (ADR 0005).
 */
final readonly class ClaimWindow
{
    private function __construct(
        public Deposit $deposit,
        public Instant $claimedAt,
        public Instant $start,
        public Instant $end,
    ) {}

    /**
     * The window of a deposit that was claimed (the reconciliation only looks at deposits that were sent).
     */
    public static function of(Deposit $deposit, WorkflowPolicy $policy): self
    {
        $claimedAt = $deposit->claimedAt();

        return new self($deposit, $claimedAt, Instants::minus($claimedAt, $policy->reconciliationSkew()), Instants::plus($claimedAt, $policy->reconciliationWindow()));
    }

    public function contains(Instant $instant): bool
    {
        return !$instant->isBefore($this->start) && !$instant->isAfter($this->end);
    }

    /**
     * Whether the return was published inside the window; false when its publication could not be parsed.
     */
    public function containsPublicationOf(ReturnReference $return): bool
    {
        $publishedAt = $return->publishedAt();

        return $publishedAt instanceof Instant && $this->contains($publishedAt);
    }

    /**
     * The smallest range covering every window.
     *
     * @param non-empty-list<self> $windows
     *
     * @return array{Instant, Instant} start and end
     */
    public static function span(array $windows): array
    {
        $start = $windows[0]->start;
        $end = $windows[0]->end;
        foreach ($windows as $window) {
            $start = $window->start->isBefore($start) ? $window->start : $start;
            $end = $window->end->isAfter($end) ? $window->end : $end;
        }

        return [$start, $end];
    }
}
