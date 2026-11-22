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

use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Runs an action that must throw and hands the exception back, to assert on its details.
 */
final class Thrown
{
    /**
     * @param callable(): mixed $action
     * @param-immediately-invoked-callable $action
     */
    public static function by(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        Assert::fail('Nothing was thrown.');
    }
}
