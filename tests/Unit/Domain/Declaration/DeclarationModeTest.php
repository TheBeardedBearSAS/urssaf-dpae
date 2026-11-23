<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

final class DeclarationModeTest extends TestCase
{
    public function testValues(): void
    {
        static::assertSame('test', DeclarationMode::Test->value);
        static::assertSame('real', DeclarationMode::Real->value);
        static::assertSame(DeclarationMode::Real, DeclarationMode::from('real'));
    }

    public function testInstallationCap(): void
    {
        static::assertTrue(DeclarationMode::Real->allows(DeclarationMode::Real));
        static::assertTrue(DeclarationMode::Real->allows(DeclarationMode::Test));
        static::assertTrue(DeclarationMode::Test->allows(DeclarationMode::Test));
        static::assertFalse(DeclarationMode::Test->allows(DeclarationMode::Real));
    }
}
