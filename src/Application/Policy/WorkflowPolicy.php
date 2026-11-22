<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Policy;

use DateInterval;
use DateTimeImmutable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;

/**
 * Every tunable of the workflow (ADR 0005, 0006). The values are package defaults, not specification values
 * (api-reference.md section 5.8): none of them is published by URSSAF.
 */
final readonly class WorkflowPolicy
{
    /**
     * The longest lookback of a first synchronisation: the consultation service keeps follow-up data 13 months (§1.2).
     */
    public const MAX_LOOKBACK = 'P13M';

    /**
     * How many deposits one reconciliation run reads at most (`withoutFlowId()`, `staleDepositing()`): the oldest claims
     * first, the rest on the next runs.
     */
    public const RECONCILIATION_BATCH = 200;

    /**
     * @param int           $maxDepositAttempts          claims after which a deposit that never reached URSSAF is `failed`
     * @param DateInterval  $staleDepositAfter           a `depositing` deposit older than this is an unknown outcome
     * @param DateInterval  $reconciliationSkew          how long before the claim a flux may be published (clock skew, time zone)
     * @param DateInterval  $reconciliationWindow        how long after the claim a flux may be published
     * @param DateInterval  $manualReviewAfter           age of the claim after which an unreconciled deposit needs a human
     * @param DateInterval  $pollingOverlap              margin kept before the instant a listing started: absorbs the unpublished time
     *                                                   zone of `publication` (Paris is UTC+1 in winter, UTC+2 in summer, so keep at
     *                                                   least `PT1H`, `PT2H` by default) and the clock skew
     * @param DateInterval  $initialLookback             how far back the first synchronisation of a tenant looks (capped at 13 months)
     * @param ?DateInterval $pollingMaxRange             longest range of one listing, null while URSSAF's limit is unknown (§5.7)
     * @param int           $pollingRetryDelaySeconds    delay advised after a polling refusal that carried no `Retry-After`
     * @param int           $maxPollingConstraintRetries consecutive refusals after which a tenant needs attention
     * @param ?bool         $autoAdopt                   whether the reconciliation may give a deposit the flux it found: `null` (default)
     *                                                   decides per deposit, `true` in `test` mode for a direct declarant and `false`
     *                                                   in `real` mode or when the declarant SIRET is unknown (a concentrator); `true`
     *                                                   or `false` forces it for every deposit. Refused adoptions go to manual review.
     *
     * @throws InvariantViolation `workflow_policy.*` for a non-positive count, delay, duration or range, or a negative skew
     */
    public function __construct(
        private int $maxDepositAttempts,
        private DateInterval $staleDepositAfter,
        private DateInterval $reconciliationSkew,
        private DateInterval $reconciliationWindow,
        private DateInterval $manualReviewAfter,
        private DateInterval $pollingOverlap,
        private DateInterval $initialLookback,
        private ?DateInterval $pollingMaxRange,
        private int $pollingRetryDelaySeconds,
        private int $maxPollingConstraintRetries,
        private ?bool $autoAdopt,
    ) {
        if ($maxDepositAttempts < 1) {
            throw InvariantViolation::because('workflow_policy.invalid_max_deposit_attempts', 'The maximum number of deposit attempts must be positive.', ['max_deposit_attempts' => $maxDepositAttempts]);
        }
        if ($pollingRetryDelaySeconds < 1) {
            throw InvariantViolation::because('workflow_policy.invalid_polling_retry_delay', 'The polling retry delay must be positive.', ['polling_retry_delay_seconds' => $pollingRetryDelaySeconds]);
        }
        if ($maxPollingConstraintRetries < 1) {
            throw InvariantViolation::because('workflow_policy.invalid_max_polling_constraint_retries', 'The maximum number of polling refusals must be positive.', ['max_polling_constraint_retries' => $maxPollingConstraintRetries]);
        }
        if ($pollingMaxRange instanceof DateInterval && !self::isPositive($pollingMaxRange)) {
            throw InvariantViolation::because('workflow_policy.invalid_polling_max_range', 'The polling range limit must be a positive interval.');
        }
        self::requirePositive('stale_deposit_after', $staleDepositAfter);
        self::requirePositive('reconciliation_window', $reconciliationWindow);
        self::requirePositive('manual_review_after', $manualReviewAfter);
        self::requirePositive('polling_overlap', $pollingOverlap);
        self::requirePositive('initial_lookback', $initialLookback);
        if (self::isNegative($reconciliationSkew)) {
            throw InvariantViolation::because('workflow_policy.invalid_reconciliation_skew', 'The reconciliation skew must not be negative.');
        }
    }

    public static function defaults(): self
    {
        return new self(
            3,
            new DateInterval('PT15M'),
            new DateInterval('PT5M'),
            new DateInterval('PT1H'),
            new DateInterval('PT6H'),
            new DateInterval('PT2H'),
            new DateInterval('P7D'),
            null,
            60,
            5,
            null,
        );
    }

    /**
     * Whether the reconciliation may give this deposit the flux it found, without a human (ADR 0005): the flux is only
     * inferred from a publication window, which is safe for a test deposit of a known declarant and not otherwise.
     */
    public function autoAdopt(Deposit $deposit): bool
    {
        return $this->autoAdopt ?? ($deposit->mode() === DeclarationMode::Test && $deposit->declarantSiret() instanceof Siret);
    }

    public function maxDepositAttempts(): int
    {
        return $this->maxDepositAttempts;
    }

    public function staleDepositAfter(): DateInterval
    {
        return $this->staleDepositAfter;
    }

    public function reconciliationSkew(): DateInterval
    {
        return $this->reconciliationSkew;
    }

    public function reconciliationWindow(): DateInterval
    {
        return $this->reconciliationWindow;
    }

    public function manualReviewAfter(): DateInterval
    {
        return $this->manualReviewAfter;
    }

    public function pollingOverlap(): DateInterval
    {
        return $this->pollingOverlap;
    }

    public function initialLookback(): DateInterval
    {
        return $this->initialLookback;
    }

    public function pollingMaxRange(): ?DateInterval
    {
        return $this->pollingMaxRange;
    }

    public function pollingRetryDelaySeconds(): int
    {
        return $this->pollingRetryDelaySeconds;
    }

    public function maxPollingConstraintRetries(): int
    {
        return $this->maxPollingConstraintRetries;
    }

    private static function requirePositive(string $name, DateInterval $interval): void
    {
        if (!self::isPositive($interval)) {
            throw InvariantViolation::because('workflow_policy.invalid_' . $name, \sprintf('The %s must be a positive interval.', str_replace('_', ' ', $name)));
        }
    }

    private static function isPositive(DateInterval $interval): bool
    {
        $origin = new DateTimeImmutable('@0');

        return $origin->add($interval) > $origin;
    }

    private static function isNegative(DateInterval $interval): bool
    {
        $origin = new DateTimeImmutable('@0');

        return $origin->add($interval) < $origin;
    }
}
