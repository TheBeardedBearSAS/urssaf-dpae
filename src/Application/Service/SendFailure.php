<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Exception\DomainException;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use Throwable;

/**
 * What a failed deposit means for the deposit (ADR 0005). Only a failure that proves the flow was not processed is
 * `NotSent`; every doubt is an unknown outcome, never a resend. A `DomainException` is `NotSent` only for the two codes
 * the package raises before anything leaves (`flow.too_large`, `declaration_mode.not_allowed`): raised after the send
 * (a reader of the answer, a bug), it proves nothing.
 */
enum SendFailure
{
    /**
     * The violation codes of a refusal made before the request: nothing was sent.
     */
    private const LOCAL_REFUSALS = ['flow.too_large', 'declaration_mode.not_allowed'];

    /**
     * The flow may have reached URSSAF: reconcile, never resend.
     */
    case OutcomeUnknown;

    /**
     * URSSAF refused the credentials: parked until requeued.
     */
    case InvalidCredentials;

    /**
     * The flow provably did not reach URSSAF (token, throttling, local refusal of `flow.too_large` or
     * `declaration_mode.not_allowed`): the deposit may be retried.
     */
    case NotSent;

    /**
     * URSSAF answered without processing the flow (HTTP 400, 404, 406, 415, 3xx): closed.
     */
    case NotProcessed;

    /**
     * Not a failure the package knows (a bug, a failing collaborator): treated as an unknown outcome and rethrown.
     */
    case Unclassified;

    public static function of(Throwable $failure): self
    {
        if (self::mayHaveReachedUrssaf($failure)) {
            return self::OutcomeUnknown;
        }
        if ($failure instanceof DomainException) {
            return \in_array($failure->violationCode(), self::LOCAL_REFUSALS, true) ? self::NotSent : self::OutcomeUnknown;
        }

        return self::ofUrssaf($failure);
    }

    private static function ofUrssaf(Throwable $failure): self
    {
        if ($failure instanceof UrssafAuthenticationFailed) {
            return $failure->isCredentialRefusal() ? self::InvalidCredentials : self::NotSent;
        }
        if ($failure instanceof RetryableException) {
            return self::NotSent;
        }

        return $failure instanceof UrssafException ? self::NotProcessed : self::Unclassified;
    }

    private static function mayHaveReachedUrssaf(Throwable $failure): bool
    {
        return $failure instanceof OutcomeUnknown || ($failure instanceof UrssafUnavailable && $failure->mayHaveReachedUrssaf());
    }
}
