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
use Throwable;

/**
 * A deposit may have reached URSSAF but its answer was lost or unreadable (ADR 0005). The package never resends it: the
 * deposit is reconciled from the return lists or left for manual review. Not retryable, hence not a
 * `RetryableException`. The message never carries the transport message (it embeds the URI): only the class name of the
 * cause is kept.
 */
final class OutcomeUnknown extends RuntimeException implements UrssafException
{
    private function __construct(string $message, private readonly Operation $operation, ?Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A transport error, a timeout or any other failure once the request was started.
     *
     * @param class-string $causeClass
     */
    public static function afterFailure(Operation $operation, string $causeClass): self
    {
        return new self(
            \sprintf('URSSAF operation %s failed with %s: the flow may have been received.', $operation->value, $causeClass),
            $operation,
            null,
        );
    }

    /**
     * Same as {@see self::afterFailure()} for a caught throwable: only its class is kept, never the throwable itself.
     */
    public static function afterCause(Operation $operation, Throwable $cause): self
    {
        return self::afterFailure($operation, $cause::class);
    }

    public static function afterStatus(Operation $operation, int $httpStatus): self
    {
        return new self(
            \sprintf('URSSAF operation %s answered HTTP %d: the flow may have been received.', $operation->value, $httpStatus),
            $operation,
            null,
        );
    }

    /**
     * @param Throwable|null $previous the package exception that found the answer unreadable
     */
    public static function unreadableAnswer(Operation $operation, string $reason, ?Throwable $previous): self
    {
        return new self(
            \sprintf('URSSAF operation %s answered an unreadable response (%s): the flow may have been received.', $operation->value, $reason),
            $operation,
            $previous,
        );
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function mayHaveReachedUrssaf(): bool
    {
        return true;
    }
}
