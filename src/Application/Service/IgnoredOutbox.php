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

use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * An outbox that keeps nothing: the default of a host that relays no integration event.
 */
final readonly class IgnoredOutbox implements Outbox
{
    public function append(IntegrationEvent ...$events): void {}

    public function pending(int $limit): array
    {
        return [];
    }

    public function markDispatched(OutboxEntry $entry, Instant $at): void {}

    public function markFailed(OutboxEntry $entry, string $error): void {}

    public function purgeDispatchedBefore(Instant $before): int
    {
        return 0;
    }
}
