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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ConfigurationRules;

final class ConfigurationRulesValuesTest extends TestCase
{
    public function testTheIndicatorsMustDiffer(): void
    {
        static::assertTrue(ConfigurationRules::indicatorsNotDistinct(['real' => 'X', 'test' => 'X']));
        static::assertFalse(ConfigurationRules::indicatorsNotDistinct(['real' => 'X', 'test' => 'Y']));
        static::assertFalse(ConfigurationRules::indicatorsNotDistinct(['real' => '', 'test' => '']));
    }

    public function testTheDeclarationModes(): void
    {
        static::assertFalse(ConfigurationRules::isUnknownDeclarationMode('test'));
        static::assertFalse(ConfigurationRules::isUnknownDeclarationMode('real'));
        static::assertFalse(ConfigurationRules::isUnknownDeclarationMode(''));
        static::assertTrue(ConfigurationRules::isUnknownDeclarationMode('production'));
        static::assertTrue(ConfigurationRules::isUnknownDeclarationMode('Test'));
        static::assertTrue(ConfigurationRules::isUnknownDeclarationMode(null));
        static::assertTrue(ConfigurationRules::isUnknownDeclarationMode(1));
    }

    public function testTheTimeZones(): void
    {
        static::assertFalse(ConfigurationRules::isUnknownTimeZone('Europe/Paris'));
        static::assertFalse(ConfigurationRules::isUnknownTimeZone('UTC'));
        static::assertTrue(ConfigurationRules::isUnknownTimeZone('Mars/Phobos'));
        static::assertTrue(ConfigurationRules::isUnknownTimeZone('europe/paris'));
        static::assertTrue(ConfigurationRules::isUnknownTimeZone(null));
    }

    public function testTheIntervals(): void
    {
        static::assertFalse(ConfigurationRules::isInvalidInterval('PT15M'));
        static::assertTrue(ConfigurationRules::isInvalidInterval('15 minutes'));
        static::assertTrue(ConfigurationRules::isInvalidInterval(15));
        static::assertTrue(ConfigurationRules::isInvalidInterval(null));
        static::assertFalse(ConfigurationRules::isInvalidOptionalInterval(null));
        static::assertFalse(ConfigurationRules::isInvalidOptionalInterval('P1D'));
        static::assertTrue(ConfigurationRules::isInvalidOptionalInterval('soon'));
    }
}
