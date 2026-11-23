<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use LogicException;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

/**
 * Container factory of the declaration mode of the installation. The configuration tree accepts the empty string because
 * it is the dummy value of an `%env()%` placeholder while the container is validated (it cannot tell it from a literal
 * empty string); an empty or unknown value is therefore refused here, at runtime, with a message that names the option.
 */
final class InstallationModeFactory
{
    /**
     * @param string $declarationMode the resolved `declaration_mode` option
     *
     * @throws LogicException when the value is empty (a missing environment variable...) or not a mode
     */
    public static function create(string $declarationMode): DeclarationMode
    {
        return DeclarationMode::tryFrom($declarationMode) ?? throw new LogicException(\sprintf(
            'The "urssaf_dpae.declaration_mode" option is %s: use "test" or "real" (check the environment variable it reads, e.g. URSSAF_DPAE_DECLARATION_MODE).',
            $declarationMode === '' ? 'empty' : \sprintf('"%s", which is not a mode', $declarationMode),
        ));
    }
}
