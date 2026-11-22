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
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;

/**
 * URSSAF refused the call itself: `authentication.invalid_parameters` (422), `deposit.bad_request` (400),
 * `return.not_owned` (403), `return.invalid_parameters` (422). Not retryable as is. The error body is never read
 * (ADR 0004), so only the stable code and the status are kept.
 */
final class UrssafRequestRejected extends RuntimeException implements UrssafException
{
    private function __construct(
        string $message,
        private readonly Operation $operation,
        private readonly int $httpStatus,
        private readonly string $reason,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function because(Operation $operation, int $httpStatus, string $reason): self
    {
        return new self(
            \sprintf('URSSAF operation %s rejected the request (HTTP %d): %s.', $operation->value, $httpStatus, $reason),
            $operation,
            $httpStatus,
            $reason,
        );
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Stable code of the refusal, `<subject>.<reason>`.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
