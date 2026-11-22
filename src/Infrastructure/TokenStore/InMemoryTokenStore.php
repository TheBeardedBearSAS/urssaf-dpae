<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\TokenStore;

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;

/**
 * Token cache kept in process memory, the only adapter shipped (ADR 0001): a token is a credential, so it reaches no
 * database, cache pool or log, and each worker authenticates on its first call.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, DpaeToken> */
    private array $tokens = [];

    public function get(string $key): ?DpaeToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function save(string $key, #[SensitiveParameter] DpaeToken $token): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
