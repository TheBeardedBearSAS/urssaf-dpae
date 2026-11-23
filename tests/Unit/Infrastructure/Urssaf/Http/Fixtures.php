<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;

/**
 * The fictitious wire fixtures of the tests.
 */
final class Fixtures
{
    /**
     * The official example flow with the test indicator: what a default (test) installation may deposit.
     */
    public static function flow(): DpaeFlow
    {
        return DpaeFlow::fromXml(
            str_replace('>120<', '>TST<', self::file('deposit_request_minimal.xml')),
            DeclarationMode::Test,
            TestIndicators::defaults(),
        );
    }

    /**
     * The official example flow as published, a real-mode one.
     */
    public static function realFlow(): DpaeFlow
    {
        return DpaeFlow::fromXml(self::file('deposit_request_minimal.xml'), DeclarationMode::Real, TestIndicators::defaults());
    }

    public static function file(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../../Fixtures/wire/' . $name);
    }
}
