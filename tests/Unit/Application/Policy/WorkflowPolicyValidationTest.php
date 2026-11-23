<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Policy;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;

final class WorkflowPolicyValidationTest extends TestCase
{
    private const POSITIVE = 'PT1S';

    /**
     * @param array{int, int, int, string} $arguments attempts, retry delay, refusals, range
     * @param array<string, int>           $params
     */
    #[DataProvider('invalid')]
    public function testItRefusesNonPositiveValues(array $arguments, string $code, array $params): void
    {
        $unit = new DateInterval(self::POSITIVE);

        try {
            new WorkflowPolicy($arguments[0], $unit, $unit, $unit, $unit, $unit, $unit, new DateInterval($arguments[3]), $arguments[1], $arguments[2], null);
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame($code, $e->violationCode());
            static::assertSame($params, $e->params());
        }
    }

    /**
     * @return iterable<string, array{array{int, int, int, string}, string, array<string, int>}>
     */
    public static function invalid(): iterable
    {
        yield 'attempts' => [[0, 1, 1, 'PT1S'], 'workflow_policy.invalid_max_deposit_attempts', ['max_deposit_attempts' => 0]];
        yield 'negative attempts' => [[-1, 1, 1, 'PT1S'], 'workflow_policy.invalid_max_deposit_attempts', ['max_deposit_attempts' => -1]];
        yield 'delay' => [[1, 0, 1, 'PT1S'], 'workflow_policy.invalid_polling_retry_delay', ['polling_retry_delay_seconds' => 0]];
        yield 'retries' => [[1, 1, 0, 'PT1S'], 'workflow_policy.invalid_max_polling_constraint_retries', ['max_polling_constraint_retries' => 0]];
        yield 'range' => [[1, 1, 1, 'PT0S'], 'workflow_policy.invalid_polling_max_range', []];
    }

    /**
     * @param int $position index of the duration among the six first of the constructor (stale, skew, window, review, overlap, lookback)
     */
    #[DataProvider('invalidDurations')]
    public function testItRefusesDurationsThatAreNotPositiveAndANegativeSkew(int $position, DateInterval $bad, string $code): void
    {
        $durations = array_fill(0, 6, new DateInterval(self::POSITIVE));
        $durations[$position] = $bad;

        $failure = Outcome::of(static fn(): WorkflowPolicy => new WorkflowPolicy(1, $durations[0], $durations[1], $durations[2], $durations[3], $durations[4], $durations[5], new DateInterval(self::POSITIVE), 1, 1, null));

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame($code, $failure->violationCode());
    }

    /**
     * @return iterable<string, array{int, DateInterval, string}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'stale delay is zero' => [0, new DateInterval('PT0S'), 'workflow_policy.invalid_stale_deposit_after'];
        yield 'stale delay is negative' => [0, self::negative(), 'workflow_policy.invalid_stale_deposit_after'];
        yield 'skew is negative' => [1, self::negative(), 'workflow_policy.invalid_reconciliation_skew'];
        yield 'window is zero' => [2, new DateInterval('PT0S'), 'workflow_policy.invalid_reconciliation_window'];
        yield 'review delay is zero' => [3, new DateInterval('PT0S'), 'workflow_policy.invalid_manual_review_after'];
        yield 'overlap is zero' => [4, new DateInterval('PT0S'), 'workflow_policy.invalid_polling_overlap'];
        yield 'lookback is zero' => [5, new DateInterval('PT0S'), 'workflow_policy.invalid_initial_lookback'];
    }

    public function testTheMessageNamesTheDurationInWords(): void
    {
        $unit = new DateInterval(self::POSITIVE);

        $failure = Outcome::of(static fn(): WorkflowPolicy => new WorkflowPolicy(1, new DateInterval('PT0S'), $unit, $unit, $unit, $unit, $unit, $unit, 1, 1, null));

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame('The stale deposit after must be a positive interval.', $failure->getMessage());
    }

    private static function negative(): DateInterval
    {
        return (new DateTimeImmutable('@1'))->diff(new DateTimeImmutable('@0'));
    }
}
