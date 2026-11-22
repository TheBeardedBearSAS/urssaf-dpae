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

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Applies one change to a deposit under optimistic locking (ADR 0005, 0008): a worker that lost the race
 * (`deposit.concurrent_modification`) reloads the deposit and lets the change look at it again, because another worker
 * (a sweep, a late answer, a reconciliation) may have moved it. Every attempt is its own unit of work: a failed one
 * leaves neither state nor events behind.
 */
final readonly class DepositUpdater
{
    public const ATTEMPTS = 3;

    public function __construct(
        private DepositRepository $deposits,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * @param-immediately-invoked-callable $change
     *
     * @param callable(Deposit, Instant): bool $change applies the change and returns true, or returns false (changing
     *                                                 nothing) when the deposit, as it now is, is no longer concerned
     *
     * @return bool whether the change was applied and saved
     *
     * @throws ConcurrentModification the deposit kept changing under every attempt
     */
    public function apply(Deposit $deposit, Instant $now, callable $change): bool
    {
        $current = $deposit;
        for ($attempt = 1; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                return $this->attempt($current, $now, $change);
            } catch (ConcurrentModification) {
                $current = $this->deposits->get($deposit->id());
            }
        }

        return $this->attempt($current, $now, $change);
    }

    /**
     * @param-immediately-invoked-callable $change
     *
     * @param callable(Deposit, Instant): bool $change
     */
    private function attempt(Deposit $deposit, Instant $now, callable $change): bool
    {
        return $this->unitOfWork->run(function (RecordedEvents $events) use ($deposit, $now, $change): bool {
            if (!$change($deposit, $now)) {
                return false;
            }
            $events->record(...$deposit->releaseEvents());
            $this->deposits->save($deposit);

            return true;
        });
    }
}
