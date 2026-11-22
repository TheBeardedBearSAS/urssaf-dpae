<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Messenger;

use Throwable;

/**
 * An operation that fails with the given failure.
 */
final readonly class FailingOperation
{
    public function __construct(private Throwable $failure) {}

    /**
     * @throws Throwable
     */
    public function __invoke(): mixed
    {
        throw $this->failure;
    }
}
