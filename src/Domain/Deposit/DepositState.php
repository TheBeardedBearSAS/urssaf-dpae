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

/**
 * Lifecycle of a deposit (ADR 0005). `Failed`, `Rejected` and `NotReceived` close it: URSSAF recorded nothing, so the
 * same reference and flow may be deposited again.
 */
enum DepositState: string
{
    case Pending = 'pending';
    case Depositing = 'depositing';
    case Received = 'received';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case OutcomeUnknown = 'outcome_unknown';
    case NotReceived = 'not_received';
    case InvalidCredentials = 'invalid_credentials';

    /**
     * Open deposits are unique per tenant on the external reference and on the flow fingerprint (ADR 0005, 0008).
     */
    public function isOpen(): bool
    {
        return !\in_array($this, [self::Failed, self::Rejected, self::NotReceived], true);
    }
}
