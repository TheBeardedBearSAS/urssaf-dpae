<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port\Urssaf;

use RuntimeException;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;

/**
 * HTTP 401, told apart by its `WWW-Authenticate` realm (ADR 0001): a credential refusal (`PasswordExpired`,
 * `InvalidCredentials`, `NotRegistered`) must be fixed by the declarant; a token refusal (`TokenInvalid`, a second
 * `TokenExpired`, `Unknown`) has already dropped the cached token, so the next call authenticates again. Not retryable
 * as is. The message never carries credentials nor a token. The raw text of the realm (not personal data, ADR 0001)
 * is kept next to the classification, so an unlisted realm can still be diagnosed.
 */
final class UrssafAuthenticationFailed extends RuntimeException implements UrssafException
{
    private function __construct(
        string $message,
        private readonly AuthenticationFailure $failure,
        private readonly ?string $realm,
    ) {
        parent::__construct($message, 401);
    }

    /**
     * @param string|null $realm the realm as sent by URSSAF, null when the answer had none
     */
    public static function because(AuthenticationFailure $failure, ?string $realm): self
    {
        return new self(\sprintf('URSSAF authentication failed (%s).', $failure->name), $failure, $realm);
    }

    /**
     * The raw text of the `WWW-Authenticate` realm, null when there was none.
     */
    public function realm(): ?string
    {
        return $this->realm;
    }

    public function failure(): AuthenticationFailure
    {
        return $this->failure;
    }

    /**
     * True for a refusal of the credentials: the tenant's records are parked until they are requeued.
     */
    public function isCredentialRefusal(): bool
    {
        return $this->failure->isCredentialRefusal();
    }
}
