<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;

final class ReturnIdentityTest extends TestCase
{
    public function testItExposesItsComponents(): void
    {
        $flowId = FlowId::fromString('F1');

        $identity = new ReturnIdentity($flowId, 10, 'ABCDE');

        static::assertSame($flowId, $identity->flowId());
        static::assertSame(10, $identity->natureCode());
        static::assertSame('ABCDE', $identity->ops());
        static::assertNull((new ReturnIdentity($flowId, 10, null))->ops());
    }

    public function testEqualityComparesEveryComponentWithoutConcatenation(): void
    {
        $base = new ReturnIdentity(FlowId::fromString('F1'), 10, 'ABCDE');

        static::assertTrue($base->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, 'ABCDE')));
        static::assertFalse($base->equals(new ReturnIdentity(FlowId::fromString('F2'), 10, 'ABCDE')));
        static::assertFalse($base->equals(new ReturnIdentity(FlowId::fromString('F1'), 11, 'ABCDE')));
        static::assertFalse($base->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, 'ABCDF')));
        static::assertFalse($base->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, null)));
    }

    public function testAnAbsentOpsIsNotAnEmptyOps(): void
    {
        $absent = new ReturnIdentity(FlowId::fromString('F1'), 10, null);

        static::assertTrue($absent->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, null)));
        static::assertFalse($absent->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, '')));
    }

    public function testComponentsCannotBeShiftedAcrossFields(): void
    {
        static::assertFalse(
            (new ReturnIdentity(FlowId::fromString('F1|10'), 11, null))
                ->equals(new ReturnIdentity(FlowId::fromString('F1'), 10, '11')),
        );
    }
}
