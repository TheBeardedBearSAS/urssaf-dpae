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
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use Throwable;

/**
 * What the deposit becomes once its send is over (ADR 0005): the answer, or the failure read through {@see SendFailure}.
 * Every change goes through {@see DepositUpdater}, because a sweep or a reconciliation may have moved the deposit while the
 * send was in flight: the answer then settles an unknown outcome, a failure leaves it unknown (the safe reading).
 */
final readonly class SendSettlement
{
    public const NOT_SENT = 'deposit.not_sent';
    public const NOT_PROCESSED = 'deposit.not_processed';
    public const OUTCOME_UNKNOWN = 'deposit.outcome_unknown';

    public function __construct(
        private DepositRepository $deposits,
        private DepositUpdater $updater,
        private WorkflowPolicy $policy,
        private Clock $clock,
    ) {}

    /**
     * @return Deposit the deposit as it is stored afterwards
     */
    public function answered(Deposit $deposit, DepositResult $result): Deposit
    {
        return $this->update($deposit, static function (Deposit $deposit, Instant $now) use ($result): bool {
            if ($deposit->state() !== DepositState::Depositing && $deposit->state() !== DepositState::OutcomeUnknown) {
                return false;
            }
            $deposit->recordResult($result, $now);

            return true;
        });
    }

    /**
     * @return Deposit the deposit as it is stored afterwards, when the failure is an answer in itself (unknown outcome,
     *                 refused credentials, request refused)
     *
     * @throws Throwable the failure, when the caller must know about it (not sent, unclassified)
     */
    public function failed(Deposit $deposit, Throwable $failure): Deposit
    {
        return match (SendFailure::of($failure)) {
            SendFailure::OutcomeUnknown => $this->unknown($deposit),
            SendFailure::InvalidCredentials => $this->whileDepositing($deposit, static function (Deposit $deposit, Instant $now): void {
                $deposit->markInvalidCredentials($now);
            }),
            SendFailure::NotProcessed => $this->whileDepositing($deposit, static function (Deposit $deposit, Instant $now): void {
                $deposit->markFailed(self::NOT_PROCESSED, $now);
            }),
            SendFailure::NotSent => throw $this->notSent($deposit, $failure),
            SendFailure::Unclassified => throw $this->afterUnknown($deposit, $failure),
        };
    }

    private function afterUnknown(Deposit $deposit, Throwable $failure): Throwable
    {
        $this->unknown($deposit);

        return $failure;
    }

    private function unknown(Deposit $deposit): Deposit
    {
        return $this->whileDepositing($deposit, static function (Deposit $deposit, Instant $now): void {
            $deposit->markOutcomeUnknown(self::OUTCOME_UNKNOWN, $now);
        });
    }

    private function notSent(Deposit $deposit, Throwable $failure): Throwable
    {
        $maxAttempts = $this->policy->maxDepositAttempts();
        $this->whileDepositing($deposit, static function (Deposit $deposit, Instant $now) use ($maxAttempts): void {
            $deposit->markNotSent(self::NOT_SENT, $maxAttempts, $now);
        });

        return $failure;
    }

    /**
     * A failure only concerns a deposit that is still `depositing`: if a sweep already moved it to `outcome_unknown`
     * (a slow call), it stays so.
     *
     * @param-immediately-invoked-callable $change
     *
     * @param callable(Deposit, Instant): void $change
     */
    private function whileDepositing(Deposit $deposit, callable $change): Deposit
    {
        return $this->update($deposit, static function (Deposit $deposit, Instant $now) use ($change): bool {
            if ($deposit->state() !== DepositState::Depositing) {
                return false;
            }
            $change($deposit, $now);

            return true;
        });
    }

    /**
     * Applies the change under optimistic locking: the deposit is reloaded and the change decides again. Returns the
     * deposit as it is stored afterwards.
     *
     * @param-immediately-invoked-callable $change
     *
     * @param callable(Deposit, Instant): bool $change returns false when the deposit, as it now is, is not concerned
     */
    private function update(Deposit $deposit, callable $change): Deposit
    {
        $this->updater->apply($deposit, Instant::fromDateTime($this->clock->now()), $change);

        return $this->deposits->get($deposit->id());
    }
}
