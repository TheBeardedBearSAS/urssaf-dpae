<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use Throwable;

/**
 * Runs an action whose failure, if any, is not what the test is about.
 */
final class Outcome
{
    /**
     * @param callable(): mixed $action
     * @param-immediately-invoked-callable $action
     *
     * @return Throwable|null what the action threw, null when it returned
     */
    public static function of(callable $action): ?Throwable
    {
        try {
            $action();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
