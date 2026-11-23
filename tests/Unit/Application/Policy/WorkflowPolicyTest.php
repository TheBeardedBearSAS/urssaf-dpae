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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Policies;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class WorkflowPolicyTest extends TestCase
{
    public function testTheDefaultsAreThePackageDefaults(): void
    {
        $policy = WorkflowPolicy::defaults();

        static::assertSame(3, $policy->maxDepositAttempts());
        static::assertSame(15, $policy->staleDepositAfter()->i);
        static::assertSame(5, $policy->reconciliationSkew()->i);
        static::assertSame(1, $policy->reconciliationWindow()->h);
        static::assertSame(6, $policy->manualReviewAfter()->h);
        static::assertSame(2, $policy->pollingOverlap()->h);
        static::assertSame(7, $policy->initialLookback()->d);
        static::assertNull($policy->pollingMaxRange());
        static::assertSame(60, $policy->pollingRetryDelaySeconds());
        static::assertSame(5, $policy->maxPollingConstraintRetries());
    }

    public function testEveryTunableIsKept(): void
    {
        $policy = new WorkflowPolicy(
            7,
            new DateInterval('PT1M'),
            new DateInterval('PT2M'),
            new DateInterval('PT3M'),
            new DateInterval('PT4M'),
            new DateInterval('PT5M'),
            new DateInterval('PT6M'),
            new DateInterval('PT7M'),
            8,
            9,
            null,
        );

        static::assertSame(7, $policy->maxDepositAttempts());
        static::assertSame(1, $policy->staleDepositAfter()->i);
        static::assertSame(2, $policy->reconciliationSkew()->i);
        static::assertSame(3, $policy->reconciliationWindow()->i);
        static::assertSame(4, $policy->manualReviewAfter()->i);
        static::assertSame(5, $policy->pollingOverlap()->i);
        static::assertSame(6, $policy->initialLookback()->i);
        static::assertSame(7, $policy->pollingMaxRange()?->i);
        static::assertSame(8, $policy->pollingRetryDelaySeconds());
        static::assertSame(9, $policy->maxPollingConstraintRetries());
    }

    public function testTheSmallestValuesAreAccepted(): void
    {
        $policy = new WorkflowPolicy(1, new DateInterval('PT1S'), new DateInterval('PT0S'), new DateInterval('PT1S'), new DateInterval('PT1S'), new DateInterval('PT1S'), new DateInterval('PT1S'), new DateInterval('PT1S'), 1, 1, null);

        static::assertSame(1, $policy->maxDepositAttempts());
    }

    #[DataProvider('adoptionsAlone')]
    public function testItLetsTheReconciliationAdoptAloneWhenItIsSafeOrForced(?bool $forced, DeclarationMode $mode, ?Siret $declarant): void
    {
        static::assertTrue(Policies::autoAdopting($forced)->autoAdopt(self::deposit($mode, $declarant)));
    }

    #[DataProvider('adoptionsNeedingAHuman')]
    public function testItKeepsAHumanInTheLoopOtherwise(?bool $forced, DeclarationMode $mode, ?Siret $declarant): void
    {
        static::assertFalse(Policies::autoAdopting($forced)->autoAdopt(self::deposit($mode, $declarant)));
    }

    /**
     * @return iterable<string, array{?bool, DeclarationMode, ?Siret}>
     */
    public static function adoptionsAlone(): iterable
    {
        yield 'test mode, direct declarant' => [null, DeclarationMode::Test, Siret::fromString(Samples::SIRET)];
        yield 'forced on in real mode, concentrator' => [true, DeclarationMode::Real, null];
    }

    /**
     * @return iterable<string, array{?bool, DeclarationMode, ?Siret}>
     */
    public static function adoptionsNeedingAHuman(): iterable
    {
        yield 'real mode' => [null, DeclarationMode::Real, Siret::fromString(Samples::SIRET)];
        yield 'concentrator (declarant unknown)' => [null, DeclarationMode::Test, null];
        yield 'forced off in test mode' => [false, DeclarationMode::Test, Siret::fromString(Samples::SIRET)];
    }

    private static function deposit(DeclarationMode $mode, ?Siret $declarant): Deposit
    {
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', Samples::NOW);

        return Deposit::request($deposit->id(), $deposit->tenantId(), $deposit->externalReference(), $deposit->fingerprint(), $mode, $declarant, Samples::at(Samples::NOW));
    }
}
