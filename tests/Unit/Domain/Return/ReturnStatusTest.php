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
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnStatus;

final class ReturnStatusTest extends TestCase
{
    public function testItIsBackedByTheUrssafCodes(): void
    {
        static::assertSame(ReturnStatus::Ok, ReturnStatus::fromCode('OK'));
        static::assertSame(ReturnStatus::Failed, ReturnStatus::fromCode('KO'));
        static::assertSame(ReturnStatus::Anomaly, ReturnStatus::fromCode('ANO'));
        static::assertSame(ReturnStatus::InProgress, ReturnStatus::fromCode('EN_COURS'));
    }

    public function testAnUnknownCodeIsNullAndCaseSensitive(): void
    {
        static::assertNull(ReturnStatus::fromCode('ok'));
        static::assertNull(ReturnStatus::fromCode('PENDING'));
    }
}
