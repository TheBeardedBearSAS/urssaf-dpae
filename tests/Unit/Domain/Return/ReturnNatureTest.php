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
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;

final class ReturnNatureTest extends TestCase
{
    public function testItIsBackedByTheUrssafCodes(): void
    {
        static::assertSame(ReturnNature::ReceiptOrRejection, ReturnNature::fromCode(10));
        static::assertSame(ReturnNature::ConformityOrAnomalies, ReturnNature::fromCode(11));
        static::assertSame(ReturnNature::IdentificationReport, ReturnNature::fromCode(20));
        static::assertSame(ReturnNature::DpaeConformity, ReturnNature::fromCode(41));
    }

    public function testAnUnknownCodeIsNull(): void
    {
        static::assertNull(ReturnNature::fromCode(50));
        static::assertNull(ReturnNature::fromCode(0));
    }
}
