<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Service\FluxCandidates;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class FluxCandidatesTest extends TestCase
{
    private const T = '2022-02-01T11:00:00Z';

    public function testItKeepsOneReceiptOrRejectionReturnPerUnlinkedFluxInListingOrder(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $holder = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T);
        $holder->startDeposit(Samples::at(self::T), 3);
        $holder->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T));
        $harness->deposits->save($holder);
        $linked = SampleReturns::listed('F1', 10, 'OK', self::T, null);
        $first = SampleReturns::listed('F2', 10, 'OK', self::T, 'OPS01');
        $duplicate = SampleReturns::listed('F2', 10, 'KO', self::T, 'OPS02');
        $otherNature = SampleReturns::listed('F3', 11, 'OK', self::T, null);
        $second = SampleReturns::listed('F4', 10, 'KO', self::T, null);

        $candidates = (new FluxCandidates($harness->deposits))->among(Samples::tenant('tenant-1'), [$linked, $first, $duplicate, $otherNature, $second]);

        static::assertSame([$first, $second], $candidates);
    }

    public function testAFluxHeldByADepositOfAnotherTenantIsStillACandidate(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $holder = Samples::deposit('d1', 'tenant-c', 'ref-1', 'a', self::T);
        $holder->startDeposit(Samples::at(self::T), 3);
        $holder->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T));
        $harness->deposits->save($holder);
        $reference = SampleReturns::listed('F1', 10, 'OK', self::T, null);

        static::assertSame([$reference], (new FluxCandidates($harness->deposits))->among(Samples::tenant('tenant-1'), [$reference]));
        static::assertSame([], (new FluxCandidates($harness->deposits))->among(Samples::tenant('tenant-1'), []));
    }
}
