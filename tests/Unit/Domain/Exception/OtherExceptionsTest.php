<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

final class OtherExceptionsTest extends TestCase
{
    public function testInvariantViolation(): void
    {
        $exception = InvariantViolation::because('deposit.too_high', 'Too high.', ['max' => 50]);

        static::assertSame('deposit.too_high', $exception->violationCode());
        static::assertSame('Too high.', $exception->getMessage());
        static::assertSame(['max' => 50], $exception->params());
        static::assertSame([], InvariantViolation::because('a.b', 'm')->params());
    }

    public function testInvalidStateTransition(): void
    {
        $exception = InvalidStateTransition::between('deposit', 'received', 'pending');

        static::assertSame('deposit.invalid_state_transition', $exception->violationCode());
        static::assertSame('Invalid deposit state transition from "received" to "pending".', $exception->getMessage());
        static::assertSame('deposit', $exception->aggregate());
        static::assertSame('received', $exception->from());
        static::assertSame('pending', $exception->to());
        static::assertSame(['aggregate' => 'deposit', 'from' => 'received', 'to' => 'pending'], $exception->params());
    }

    public function testRecordNotFound(): void
    {
        $exception = RecordNotFound::withIdentifier('return', 'abc-1');

        static::assertSame('return.not_found', $exception->violationCode());
        static::assertSame('No return found for identifier "abc-1".', $exception->getMessage());
        static::assertSame('return', $exception->recordType());
        static::assertSame('abc-1', $exception->identifier());
        static::assertSame(['record_type' => 'return', 'identifier' => 'abc-1'], $exception->params());
    }

    public function testValidationFailedCarriesTheResult(): void
    {
        $result = ValidationResult::of(
            Violation::error('a', 'a.invalid', 'A.'),
            Violation::warning('b', 'b.odd', 'B.'),
            Violation::error('c', 'c.invalid', 'C.'),
        );

        $exception = ValidationFailed::withResult($result);

        static::assertSame($result, $exception->result());
        static::assertSame('validation.failed', $exception->violationCode());
        static::assertSame('Validation failed with 2 error(s).', $exception->getMessage());
        static::assertSame(['error_count' => 2], $exception->params());
    }
}
