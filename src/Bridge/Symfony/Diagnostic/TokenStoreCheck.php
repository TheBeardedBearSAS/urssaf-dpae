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

use Override;

/**
 * The token is a credential: by default it lives in the memory of the process (ADR 0001); a host may provide a store.
 */
final readonly class TokenStoreCheck implements InstallationCheck
{
    private const NAME = 'token store';

    /**
     * @param ?string $service the "token_store.service" option
     */
    public function __construct(private ?string $service) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        return [$this->service === null
            ? new CheckResult(self::NAME, '', CheckStatus::Ok, 'In memory, per process: every process authenticates once (ADR 0001).')
            : new CheckResult(self::NAME, '', CheckStatus::Ok, \sprintf('Service "%s": the token is a credential, keep it out of logs and backups.', $this->service))];
    }
}
