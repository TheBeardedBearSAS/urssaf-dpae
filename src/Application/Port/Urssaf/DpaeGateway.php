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

use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The three URSSAF DPAE services (ADR 0004 to 0006). Every method may also throw
 * {@see UrssafAuthenticationFailed} (a credential refusal, a token refused after the replay) and
 * {@see UrssafUnavailable} (a failed token call or a transient failure), plus the adapter's own exception
 * (`UnexpectedUrssafResponse` for the HTTP adapter) for an answer the contract does not describe.
 */
interface DpaeGateway
{
    /**
     * Deposits one flow, as is. 200 is an electronic receipt, 422 with an XML body is a rejection notice.
     *
     * A deposit is never resent by the package once it may have reached URSSAF. The use case reads what an adapter throws
     * as follows (`SendFailure`), so an adapter must keep to this contract:
     *
     * - provably not sent, retried later: `UrssafUnavailable` without `mayHaveReachedUrssaf()`, a non-credential
     *   `UrssafAuthenticationFailed`, `PollingConstraintViolated`, and the local refusals `InvalidValue` `flow.too_large` and
     *   `InvariantViolation` `declaration_mode.not_allowed` (any other `DomainException` is an unknown outcome);
     * - credentials refused: `UrssafAuthenticationFailed` for a credential refusal (the deposit is parked);
     * - answered without processing, closed: any other `UrssafException` (`UrssafRequestRejected`, an unexpected status);
     * - everything else, including `OutcomeUnknown` and any other `Throwable`, is an unknown outcome: reconcile, never resend.
     *
     * @throws OutcomeUnknown            the flow may have been received (transport error, 5xx, unreadable answer): reconcile, never resend blindly
     * @throws UrssafRequestRejected     HTTP 400 (`deposit.bad_request`): not processed
     * @throws PollingConstraintViolated HTTP 429
     * @throws InvalidValue              `flow.too_large` when the flow exceeds the configured limit: nothing was sent
     */
    public function deposit(TenantId $tenantId, DpaeFlow $flow): DepositResult;

    /**
     * Lists the returns published from `$from` (one-date path) or in `[$from, $to]` (two-date path).
     *
     * @return list<ReturnReference>
     *
     * @throws PollingConstraintViolated HTTP 422 or 429, retryable
     * @throws InvariantViolation        `returns.range_inverted` when `$from` is after `$to`
     */
    public function listReturns(TenantId $tenantId, Instant $from, ?Instant $to): array;

    /**
     * Downloads the document of a return, bytes and `Content-Type` as received.
     *
     * @throws ReturnNotDownloadable     the return has no document, or its `url` is not on the download origin
     * @throws ReturnNoLongerAvailable   HTTP 404: unknown or purged return
     * @throws UrssafRequestRejected     HTTP 403 (`return.not_owned`) or 422 (`return.invalid_parameters`)
     * @throws PollingConstraintViolated HTTP 429
     * @throws InvalidValue              `return_id.too_long` when the stored legacy `id` is out of bounds
     */
    public function downloadReturn(TenantId $tenantId, ReturnReference $return): ReturnContent;
}
