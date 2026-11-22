<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Standalone;

use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Standalone\UrssafDpaeBuilder;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SequentialIdGenerator;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

trait UrssafDpaeBuilderFixtures
{
    private static function stack(ApiConfiguration $configuration): HttpStack
    {
        return HttpStack::standard($configuration, ServiceCode::direct());
    }

    private static function builder(HttpStack $stack): UrssafDpaeBuilder
    {
        return UrssafDpaeBuilder::create(
            new HttpTransport($stack->client, $stack->factory, $stack->factory),
            new InMemoryCredentialsProvider([HttpStack::TENANT => $stack->credentials]),
            $stack->configuration,
        );
    }

    private static function command(DeclarationMode $mode, string $variant): DepositDpae
    {
        return new DepositDpae(Samples::tenant(HttpStack::TENANT), Samples::reference('ref-' . $variant), Samples::flow($mode, $variant));
    }

    private static function simulated(): SimulatedDpaeGateway
    {
        return new SimulatedDpaeGateway(new FixedClock(Samples::NOW), new SequentialIdGenerator());
    }
}
