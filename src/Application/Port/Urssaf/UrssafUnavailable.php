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
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use Throwable;

/**
 * Transient failure: network error, timeout, 5xx, or a failed token call. Retryable.
 *
 * {@see self::mayHaveReachedUrssaf()} tells whether URSSAF may have processed the call anyway: true for a transport
 * error and a 5xx answer of the API call itself, false when the token could not be obtained (the call was never sent).
 * The deposit turns the first kind into {@see OutcomeUnknown} and never resends it blindly.
 *
 * The PSR-18 exception is never chained: its message embeds the URI and some clients keep the request, hence the
 * `Authorization` header. Only the class name of the cause is kept ({@see self::causeClass()}).
 */
final class UrssafUnavailable extends RuntimeException implements RetryableException
{
    private function __construct(
        string $message,
        private readonly Operation $operation,
        private readonly ?int $httpStatus,
        private readonly ?int $retryAfterSeconds,
        private readonly bool $mayHaveReachedUrssaf,
        /** @var class-string<Throwable>|null */
        private readonly ?string $causeClass,
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    /**
     * The call answered a transient HTTP status (5xx).
     *
     * @param int|null $retryAfterSeconds delay given by the `Retry-After` header, when there is one
     */
    public static function withStatus(Operation $operation, int $httpStatus, ?int $retryAfterSeconds): self
    {
        return new self(
            \sprintf('URSSAF operation %s is temporarily unavailable (HTTP %d).', $operation->value, $httpStatus),
            $operation,
            $httpStatus,
            $retryAfterSeconds,
            true,
            null,
        );
    }

    /**
     * The call failed without an HTTP answer (connection error, timeout). Only the class of `$cause` is kept.
     */
    public static function dueToTransportError(Operation $operation, Throwable $cause): self
    {
        return new self(
            \sprintf('URSSAF operation %s could not be reached.', $operation->value),
            $operation,
            null,
            null,
            true,
            $cause::class,
        );
    }

    /**
     * The token needed by the call could not be obtained (HTTP status, or transport error when `$httpStatus` is null):
     * the call itself was not sent.
     *
     * @param Throwable|null $cause the transport failure, if any: only its class is kept
     */
    public static function whileAuthenticating(
        Operation $operation,
        ?int $httpStatus,
        ?int $retryAfterSeconds,
        ?Throwable $cause,
    ): self {
        return new self(
            \sprintf('URSSAF authentication for operation %s is temporarily unavailable.', $operation->value),
            $operation,
            $httpStatus,
            $retryAfterSeconds,
            false,
            $cause instanceof Throwable ? $cause::class : null,
        );
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    /**
     * Null for a transport error (no HTTP answer).
     */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Class of the transport failure, null for an answered status. Never the exception itself nor its message.
     *
     * @return class-string<Throwable>|null
     */
    public function causeClass(): ?string
    {
        return $this->causeClass;
    }

    public function mayHaveReachedUrssaf(): bool
    {
        return $this->mayHaveReachedUrssaf;
    }
}
