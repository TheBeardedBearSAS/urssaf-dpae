<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\ListReturnsCommand;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;

final class ListReturnsCommandTest extends TestCase
{
    public function testTheOpsOfAReturnIsListedToBePassedToTheDownload(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $harness->gateway->willList([
            SampleReturns::listed('F1', 20, 'OK', '2022-02-01T11:00:00Z', 'OPS-42'),
            SampleReturns::listed('F2', 10, 'OK', '2022-02-01T11:30:00Z', null),
        ]);
        $tester = new CommandTester(new ListReturnsCommand($harness->listHandler(), $harness->clock, $harness->policy, ['tenant-1']));

        static::assertSame(0, $tester->execute([]));

        $display = $tester->getDisplay();
        static::assertMatchesRegularExpression('/\| Flux +\| Nature code +\| Status code +\| Ops +\| Publication +\| Document +\|/', $display);
        static::assertMatchesRegularExpression('/\| F1 +\| 20 +\| OK +\| OPS-42 +\| /', $display);
        static::assertMatchesRegularExpression('/\| F2 +\| 10 +\| OK +\| - +\| /', $display);
    }
}
