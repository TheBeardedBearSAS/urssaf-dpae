<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic;

use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * What `urssaf:dpae:check` checks: the tenants, and whether URSSAF may be called.
 */
final readonly class CheckContext
{
    /**
     * @param non-empty-list<TenantId> $tenants
     */
    private function __construct(
        public array $tenants,
        public bool $remoteCallsAllowed,
    ) {}

    /**
     * @param non-empty-list<TenantId> $tenants
     */
    public static function withRemoteCalls(array $tenants): self
    {
        return new self($tenants, true);
    }

    /**
     * @param non-empty-list<TenantId> $tenants
     */
    public static function withoutRemoteCalls(array $tenants): self
    {
        return new self($tenants, false);
    }
}
