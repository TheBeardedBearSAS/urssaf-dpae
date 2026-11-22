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

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;

/**
 * Cache of DPAE tokens. A token is a credential: the only adapter shipped keeps it in process memory (ADR 0001), and an
 * implementation must never log, serialize or share it. Keys are opaque strings built by the token provider.
 */
interface TokenStore
{
    public function get(string $key): ?DpaeToken;

    public function save(string $key, #[SensitiveParameter] DpaeToken $token): void;

    public function delete(string $key): void;
}
