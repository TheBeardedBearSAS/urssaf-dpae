<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger;

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

/**
 * Asks to deposit one DPAE flow. The payload is scalar: the flow travels base 64 encoded (it may not be UTF-8) and carries
 * personal data, so the transport that stores the message must be protected like the database (docs/symfony.md). The mode is
 * required: it is never implied (ADR 0003).
 */
final readonly class DepositDpaeMessage
{
    /**
     * @param string $tenantId          the tenant whose credentials deposit the flow
     * @param string $externalReference your reference of the declaration (no personal data), unique per tenant
     * @param string $mode              "test" or "real"
     * @param string $flow              the flow bytes, base 64 encoded
     */
    public function __construct(
        public string $tenantId,
        public string $externalReference,
        public string $mode,
        #[SensitiveParameter]
        public string $flow,
    ) {}

    public static function of(string $tenantId, string $externalReference, DeclarationMode $mode, #[SensitiveParameter] string $flowBytes): self
    {
        return new self($tenantId, $externalReference, $mode->value, base64_encode($flowBytes));
    }
}
