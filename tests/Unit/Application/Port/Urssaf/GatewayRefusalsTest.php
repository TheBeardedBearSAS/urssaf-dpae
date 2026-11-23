<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Port\Urssaf;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use Throwable;

final class GatewayRefusalsTest extends TestCase
{
    public function testARejectedRequestKeepsItsStableCode(): void
    {
        $exception = UrssafRequestRejected::because(Operation::DownloadReturn, 403, 'return.not_owned');

        static::assertTrue(self::is(UrssafException::class, $exception));
        static::assertFalse(self::is(RetryableException::class, $exception));
        static::assertSame('URSSAF operation download_return rejected the request (HTTP 403): return.not_owned.', $exception->getMessage());
        static::assertSame(Operation::DownloadReturn, $exception->operation());
        static::assertSame(403, $exception->httpStatus());
        static::assertSame(403, $exception->getCode());
        static::assertSame('return.not_owned', $exception->reason());
    }

    public function testThePollingConstraintIsRetryableAndKeepsTheStatus(): void
    {
        $exception = PollingConstraintViolated::withStatus(422, 60);

        static::assertTrue(self::is(RetryableException::class, $exception));
        static::assertSame('URSSAF refused the polling (HTTP 422).', $exception->getMessage());
        static::assertSame(422, $exception->status());
        static::assertSame(422, $exception->getCode());
        static::assertSame(60, $exception->retryAfterSeconds());
        static::assertNull(PollingConstraintViolated::withStatus(429, null)->retryAfterSeconds());
    }

    public function testAnUnknownOrPurgedReturnKeepsItsIdentity(): void
    {
        $return = self::reference(null, null);
        $exception = ReturnNoLongerAvailable::forReturn($return);

        static::assertTrue(self::is(UrssafException::class, $exception));
        static::assertFalse(self::is(RetryableException::class, $exception));
        static::assertSame('The URSSAF return is unknown or no longer available (HTTP 404).', $exception->getMessage());
        static::assertSame(404, $exception->getCode());
        static::assertTrue($return->identity()->equals($exception->identity()));
    }

    public function testAReturnWithoutDocumentIsNotDownloadable(): void
    {
        $return = self::reference(null, null);
        $exception = ReturnNotDownloadable::withoutDocument($return);

        static::assertTrue(self::is(UrssafException::class, $exception));
        static::assertFalse(self::is(RetryableException::class, $exception));
        static::assertSame('The URSSAF return has neither a download url nor an id.', $exception->getMessage());
        static::assertTrue($return->identity()->equals($exception->identity()));
        static::assertNull($exception->urlOrigin());
    }

    public function testAUrlOutsideTheDownloadOriginReportsOnlyItsOrigin(): void
    {
        $return = self::reference(null, 'https://evil.example/secret?x=1');
        $exception = ReturnNotDownloadable::urlOutsideDownloadOrigin($return, 'https://evil.example:443');

        static::assertSame(
            'The url of the URSSAF return is not on the configured download origin and the return has no id.',
            $exception->getMessage(),
        );
        static::assertTrue($return->identity()->equals($exception->identity()));
        static::assertSame('https://evil.example:443', $exception->urlOrigin());
    }

    public function testAnUnknownOutcomeMayHaveReachedUrssafAndIsNotRetryable(): void
    {
        $failure = OutcomeUnknown::afterFailure(Operation::Deposit, RuntimeException::class);
        $status = OutcomeUnknown::afterStatus(Operation::Deposit, 502);
        $cause = new RuntimeException('cause');
        $unreadable = OutcomeUnknown::unreadableAnswer(Operation::Deposit, 'empty body', $cause);

        static::assertTrue(self::is(UrssafException::class, $failure));
        static::assertFalse(self::is(RetryableException::class, $failure));
        static::assertSame('URSSAF operation deposit failed with RuntimeException: the flow may have been received.', $failure->getMessage());
        static::assertSame('URSSAF operation deposit answered HTTP 502: the flow may have been received.', $status->getMessage());
        static::assertSame(
            'URSSAF operation deposit answered an unreadable response (empty body): the flow may have been received.',
            $unreadable->getMessage(),
        );
        static::assertSame($cause, $unreadable->getPrevious());
        static::assertNull($failure->getPrevious());
        static::assertNull($status->getPrevious());
        foreach ([$failure, $status, $unreadable] as $exception) {
            static::assertSame(Operation::Deposit, $exception->operation());
            static::assertSame(0, $exception->getCode());
            static::assertTrue($exception->mayHaveReachedUrssaf());
        }
    }

    private static function reference(?string $returnId, ?string $url): ReturnReference
    {
        return ReturnReference::fromListing(
            FlowId::fromString('F1'),
            10,
            'OK',
            '20220130083000',
            '20220130082500',
            null,
            null,
            $returnId,
            null,
            null,
            $url,
        );
    }

    /**
     * @param class-string $type
     */
    private static function is(string $type, Throwable $exception): bool
    {
        return $exception instanceof $type;
    }
}
