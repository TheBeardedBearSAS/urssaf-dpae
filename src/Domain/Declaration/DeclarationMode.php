<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

/**
 * Whether a flow is a test or a real declaration (ADR 0003). "Production" means `Real` everywhere in this package.
 */
enum DeclarationMode: string
{
    case Test = 'test';
    case Real = 'real';

    /**
     * Whether an installation capped at this mode may send a flow of the given mode: `Real` allows both, `Test` only
     * `Test`.
     */
    public function allows(self $flowMode): bool
    {
        return $this === self::Real || $flowMode === self::Test;
    }
}
