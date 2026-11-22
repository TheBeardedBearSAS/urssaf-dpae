<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\DependencyInjection;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\InstallationModeFactory;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

final class InstallationModeFactoryTest extends TestCase
{
    public function testTheInstallationModeIsBuiltFromTheResolvedOption(): void
    {
        static::assertSame(DeclarationMode::Test, InstallationModeFactory::create('test'));
        static::assertSame(DeclarationMode::Real, InstallationModeFactory::create('real'));
    }

    public function testAnEmptyOrUnknownInstallationModeIsRefusedWithAClearMessage(): void
    {
        try {
            InstallationModeFactory::create('');
            static::fail('An empty mode must be refused.');
        } catch (LogicException $failure) {
            static::assertStringContainsString('The "urssaf_dpae.declaration_mode" option is empty: use "test" or "real"', $failure->getMessage());
            static::assertStringContainsString('URSSAF_DPAE_DECLARATION_MODE', $failure->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('option is "prod", which is not a mode');

        InstallationModeFactory::create('prod');
    }
}
