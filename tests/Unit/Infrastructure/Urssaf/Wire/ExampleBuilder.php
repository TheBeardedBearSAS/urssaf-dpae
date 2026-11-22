<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationVocabulary;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\DpaeFlowBuilder;

/**
 * A builder with the package defaults.
 */
final class ExampleBuilder
{
    public static function defaults(): DpaeFlowBuilder
    {
        return new DpaeFlowBuilder(TestIndicators::defaults(), DeclarationVocabulary::defaults());
    }
}
