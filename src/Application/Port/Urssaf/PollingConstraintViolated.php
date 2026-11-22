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
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;

/**
 * HTTP 429, or 422 on a list call (ADR 0006: the temporary form of the 429): URSSAF refuses the polling. Retryable.
 * The status is kept because a persistent 422 may also mean invalid parameters.
 */
final class PollingConstraintViolated extends RuntimeException implements RetryableException
{
    private function __construct(string $message, private readonly int $status, private readonly ?int $retryAfterSeconds)
    {
        parent::__construct($message, $status);
    }

    /**
     * @param int|null $retryAfterSeconds delay given by the `Retry-After` header (seconds or HTTP date), when there is one
     */
    public static function withStatus(int $status, ?int $retryAfterSeconds): self
    {
        return new self(\sprintf('URSSAF refused the polling (HTTP %d).', $status), $status, $retryAfterSeconds);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Null when the answer carried no usable `Retry-After`: the caller applies its own delay.
     */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
