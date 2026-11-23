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
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;

/**
 * Container factory of the simulated gateway: the runtime guard of the `gateway.simulated` rule for a `declaration_mode`
 * that is an `%env()%` placeholder at build time (the build-time rule only sees a literal `real`), as the CESU and PAJE
 * packages do for their environment (ADR 0003, 0009). The DPAE has no production host: "production" is the `real` mode.
 */
final class SimulatedGatewayFactory
{
    /**
     * @param string $declarationMode the resolved `declaration_mode` option
     *
     * @throws LogicException when the mode is `real`, or not a mode at all (fail closed)
     */
    public static function create(string $declarationMode, Clock $clock, IdGenerator $ids): SimulatedDpaeGateway
    {
        if (DeclarationMode::tryFrom($declarationMode) !== DeclarationMode::Test) {
            throw new LogicException('"gateway.simulated" is meant for tests: it requires the "test" declaration mode.');
        }

        return new SimulatedDpaeGateway($clock, $ids);
    }
}
