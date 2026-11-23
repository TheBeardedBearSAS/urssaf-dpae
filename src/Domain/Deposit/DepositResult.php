<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Synchronous answer of a deposit: an {@see ElectronicReceipt} (HTTP 200) or a {@see RejectionNotice} (HTTP 422),
 * mutually exclusive for one flow (ADR 0005). Sealed by convention: only those two classes implement it, PHP 8.2 has
 * no sealed interfaces.
 *
 * Every extracted field is nullable: the wire form is not published (api-reference.md §5.9), so a field that cannot be
 * located is null while the answer is still the verbatim {@see RawDocument}.
 */
interface DepositResult
{
    /**
     * `IdFluxDPAE` read from the document.
     */
    public function flowId(): ?FlowId;

    /**
     * SIRET of the identified declarant (`envoi_identification.declarant.siret`) read from the document.
     */
    public function declarantSiret(): ?Siret;

    /**
     * Generation date read from the document.
     */
    public function generatedAt(): ?Instant;

    /**
     * The answer, kept verbatim.
     */
    public function document(): RawDocument;
}
