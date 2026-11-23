<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class SimulatedDpaeGatewayReturnsTest extends TestCase
{
    use SimulatedGatewayFixtures;

    public function testReturnsAreListedByTenantAndPublicationRangeAsNatureTenOnly(): void
    {
        $clock = new FixedClock('2022-02-01T10:00:00Z');
        $gateway = self::gateway($clock);
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
        $clock->moveTo('2022-02-01T11:00:00Z');
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'b'));
        $clock->moveTo('2022-02-01T12:00:00Z');
        $gateway->deposit(Samples::tenant('tenant-c'), Samples::flow(DeclarationMode::Test, 'c'));
        $ids = static fn(array $returns): array => array_map(static fn(ReturnReference $return): string => $return->flowId()->value(), $returns);

        $all = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), null);
        $range = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:01Z'), Samples::at('2022-02-01T11:00:00Z'));
        $edges = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:00:00Z'));

        static::assertSame(['id-1', 'id-2'], $ids($all));
        static::assertSame(['id-2'], $ids($range));
        static::assertSame(['id-1'], $ids($edges));
        static::assertSame(['id-3'], $ids($gateway->listReturns(Samples::tenant('tenant-c'), Samples::at('2022-02-01T00:00:00Z'), null)));
        foreach ($all as $return) {
            static::assertSame(ReturnNature::ReceiptOrRejection->value, $return->natureCode());
            static::assertSame('OK', $return->statusCode());
            static::assertTrue($return->hasDocument());
            static::assertNull($return->ops());
        }
        static::assertSame('20220201100000', $all[0]->publicationRaw());
        static::assertSame('2022-02-01T10:00:00Z', $all[0]->publishedAt()?->toIso8601());
        static::assertSame('2022-02-01T10:00:00Z', $all[0]->producedAt()?->toIso8601());
        static::assertSame('1', $all[0]->returnId());
        static::assertSame('2', $all[1]->returnId());
    }

    public function testAnInvertedRangeIsRefused(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));

        try {
            $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T11:00:00Z'), Samples::at('2022-02-01T10:00:00Z'));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('returns.range_inverted', $e->violationCode());
        }
        static::assertSame([], $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:00:00Z')));
    }

    public function testTheDocumentOfAReturnIsDownloaded(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
        $return = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null)[0];

        $content = $gateway->downloadReturn(Samples::tenant('tenant-1'), $return);

        static::assertSame('application/xml', $content->contentType());
        static::assertSame('<envoi><IdFluxDPAE>id-1</IdFluxDPAE></envoi>', $content->bytes());
        static::assertSame(Operation::DownloadReturn, $gateway->calls()[2]);
    }

    public function testAnotherTenantsReturnIsForbidden(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
        $return = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null)[0];

        try {
            $gateway->downloadReturn(Samples::tenant('tenant-c'), $return);
            static::fail('UrssafRequestRejected expected.');
        } catch (UrssafRequestRejected $e) {
            static::assertSame(403, $e->httpStatus());
            static::assertSame('return.not_owned', $e->reason());
        }
    }

    public function testAPurgedOrUnknownReturnIsGone(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'b'));
        [$first, $second] = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null);
        $gateway->purge($first->flowId());
        $unknown = SampleReturns::listed('F9', 10, 'OK', Samples::NOW, null);
        $otherNature = SampleReturns::listed('id-2', 11, 'OK', Samples::NOW, null);

        foreach ([$first, $unknown, $otherNature] as $gone) {
            $failure = Thrown::by(static fn(): ReturnContent => $gateway->downloadReturn(Samples::tenant('tenant-1'), $gone));

            static::assertInstanceOf(ReturnNoLongerAvailable::class, $failure);
        }
        static::assertStringContainsString('id-2', $gateway->downloadReturn(Samples::tenant('tenant-1'), $second)->bytes());
    }

    public function testAReturnWithoutDocumentIsNotDownloadable(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));

        $this->expectException(ReturnNotDownloadable::class);

        $gateway->downloadReturn(Samples::tenant('tenant-1'), SampleReturns::listedWithoutDocument('id-1', 10, 'OK', Samples::NOW, null));
    }

    public function testADownloadFailureCanBeScripted(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->failNext(Operation::DownloadReturn, new RuntimeException('scripted'));

        $this->expectException(RuntimeException::class);

        $gateway->downloadReturn(Samples::tenant('tenant-1'), SampleReturns::listed('id-1', 10, 'OK', Samples::NOW, null));
    }
}
