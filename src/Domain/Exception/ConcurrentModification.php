<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Exception;

/**
 * Thrown by a repository when an aggregate was changed by someone else since it was read (optimistic lock). The caller
 * reloads the aggregate and decides again; nothing of the failed save is kept.
 *
 * Violation code: "<aggregate>.concurrent_modification".
 */
final class ConcurrentModification extends DomainException implements RetryableException
{
    public static function of(string $aggregate, string $identifier): self
    {
        return new self(
            \sprintf('The %s "%s" was modified concurrently.', $aggregate, $identifier),
            $aggregate . '.concurrent_modification',
            ['aggregate' => $aggregate, 'identifier' => $identifier],
        );
    }
}
