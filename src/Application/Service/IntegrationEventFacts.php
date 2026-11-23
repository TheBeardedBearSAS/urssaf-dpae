<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Stable identifiers of integration events: the same fact always has the same id, so that a consumer deduplicates on it
 * (the relay delivers at least once).
 */
final class IntegrationEventFacts
{
    /**
     * @param list<scalar|null> $facts the values that make the fact what it is
     */
    public static function id(string $type, string $aggregateId, Instant $occurredAt, array $facts): string
    {
        $hash = hash('sha256', serialize([$type, $aggregateId, $occurredAt->toIso8601(), $facts]));

        return \sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}
