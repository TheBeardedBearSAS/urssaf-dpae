<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port;

/**
 * Runs a state change atomically: everything the callable writes is committed together or not at all.
 */
interface TransactionManager
{
    /**
     * Commits when the callable returns, rolls back and rethrows when it throws.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $operation
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
