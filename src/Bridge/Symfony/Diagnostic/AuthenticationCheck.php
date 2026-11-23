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
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\DpaeTokenProvider;

/**
 * URSSAF grants a token to every tenant: any cached token is dropped first, so that it never hides refused credentials.
 * The token provider given here must use a token store of its own (the bundle gives it an in-memory one): the tokens of the
 * application are left untouched. The token is never printed.
 */
final readonly class AuthenticationCheck implements InstallationCheck
{
    private const NAME = 'authentication';

    public function __construct(
        private CredentialsProvider $credentials,
        private DpaeTokenProvider $tokens,
    ) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        if (!$context->remoteCallsAllowed) {
            return [new CheckResult(self::NAME, '', CheckStatus::Skipped, 'Not checked (--skip-remote).')];
        }

        return array_map($this->check(...), $context->tenants);
    }

    private function check(TenantId $tenantId): CheckResult
    {
        try {
            $credentials = $this->credentials->forTenant($tenantId);
            $this->tokens->invalidate($tenantId, $credentials);
            $this->tokens->token($tenantId, $credentials, Operation::Authenticate);
        } catch (UrssafException $failure) {
            return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Failure, $failure->getMessage());
        }

        return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Ok, 'Token granted by URSSAF.');
    }
}
