<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Contract;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire\ExampleBuilder;

/**
 * Live checks against the URSSAF hosts. Opt-in, never part of coverage or mutation gates, **always in test mode**: the
 * installation ceiling is `DeclarationMode::Test` and every flow carries the test indicator, there is no real mode here.
 *
 * Skipped when none of `URSSAF_DPAE_SIRET`, `_NOM`, `_PRENOM`, `_PASSWORD` and `_SERVICE` is set (a partial or invalid set fails) (see {@see LiveGateway} and
 * `make test-contract`). The answers of the list and download calls and of the deposit are kept as samples in
 * `var/contract-samples` ({@see SampleRecorder}).
 *
 * First goals (docs/roadmap.md, api-reference.md section 5), each answered by one test:
 *
 * - the hosts and paths (section 5.1): every test reaches at least one host, a wrong host fails the suite;
 * - the shape of the authentication XML (section 5.4): `testTheAuthenticationXmlIsAccepted`;
 * - the date formats and the zone of the list paths (section 5.8): both list tests;
 * - the test indicator (section 5.2) and a flow built without raison sociale (section 5.12): `testDepositsATestFlow`,
 *   the only call that writes, so opt-in with `URSSAF_DPAE_CONTRACT_DEPOSIT=1`;
 * The list calls are paced ({@see self::LIST_PAUSE_SECONDS}). A polling refusal (`PollingConstraintViolated`, HTTP 422 or
 * 429, retryable, ADR 0006) is a documented outcome of the hosts, not a defect of the package: the test is skipped with
 * the status and the `Retry-After` delay, and is run again later. A refusal that persists on a narrow range is a finding
 * to record in api-reference.md section 5.7.
 *
 * - samples of the AEE, the rejection notice, the list answer and the returns (section 5.9): the recorder.
 */
#[Group('contract')]
final class LiveContractTest extends TestCase
{
    /**
     * Seconds left between two list calls of the suite: the quotas of the list paths are not published (api-reference.md
     * sections 1.1 and 5.7), so the suite stays far below any plausible one.
     */
    private const LIST_PAUSE_SECONDS = 2;

    /**
     * The download test looks for a document window by window, newest first, instead of one wide range (the maximum
     * range is not published either).
     */
    private const DOWNLOAD_WINDOW_HOURS = 24 * 7;

    private const DOWNLOAD_WINDOWS = 4;

    private static bool $listedOnce = false;

    private LiveGateway $live;

    protected function setUp(): void
    {
        $live = LiveGateway::fromEnvironment();
        if (!$live instanceof LiveGateway) {
            static::markTestSkipped(
                'URSSAF contract tests are opt-in: set URSSAF_DPAE_SIRET, URSSAF_DPAE_NOM, URSSAF_DPAE_PRENOM, URSSAF_DPAE_PASSWORD and URSSAF_DPAE_SERVICE (see `make test-contract`).',
            );
        }

        $this->live = $live;
    }

    public function testTheInstallationIsInTestModeAndRefusesARealFlow(): void
    {
        static::assertSame(DeclarationMode::Test, $this->live->configuration->declarationMode());

        $realFlow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Real);

        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessageMatches('/real declaration/');
        $this->live->gateway->deposit($this->live->tenantId, $realFlow);
    }

    public function testTheAuthenticationXmlIsAccepted(): void
    {
        try {
            $references = $this->listReturns(self::hoursAgo(1), null);
        } catch (UrssafAuthenticationFailed $failure) {
            static::fail(\sprintf(
                'Authentication refused (%s, realm "%s"): check the credentials, the service code and the authentication host and XML shape (api-reference.md sections 1.3, 5.4).',
                $failure->failure()->name,
                $failure->realm() ?? '-',
            ));
        }

        static::assertContainsOnlyInstancesOf(ReturnReference::class, $references);
    }

    public function testListsTheReturnsFromADate(): void
    {
        $references = $this->listReturns(self::hoursAgo(24), null);

        static::assertContainsOnlyInstancesOf(ReturnReference::class, $references);
        self::assertDatesAreReadable($references);
    }

    public function testListsTheReturnsOfADateRange(): void
    {
        $references = $this->listReturns(self::hoursAgo(24), self::hoursAgo(1));

        static::assertContainsOnlyInstancesOf(ReturnReference::class, $references);
        self::assertDatesAreReadable($references);
    }

    public function testDownloadsADocumentOfTheLastReturns(): void
    {
        for ($window = 0; $window < self::DOWNLOAD_WINDOWS; ++$window) {
            $from = self::hoursAgo(self::DOWNLOAD_WINDOW_HOURS * ($window + 1));
            $to = self::hoursAgo(self::DOWNLOAD_WINDOW_HOURS * $window);
            foreach ($this->listReturns($from, $to) as $reference) {
                if (!$reference->hasDocument()) {
                    continue;
                }

                $content = $this->live->gateway->downloadReturn($this->live->tenantId, $reference);

                static::assertNotSame('', $content->bytes());
                static::assertNotSame('', $content->contentType());

                return;
            }
        }

        static::markTestSkipped('No return with a document in the last 28 days: nothing to download.');
    }

    public function testDepositsATestFlow(): void
    {
        if (LiveGateway::env('URSSAF_DPAE_CONTRACT_DEPOSIT') !== '1') {
            static::markTestSkipped('The test deposit is the only call that writes: set URSSAF_DPAE_CONTRACT_DEPOSIT=1 to run it.');
        }

        $flow = ExampleBuilder::defaults()->build(ExampleDeclaration::declaration(), DeclarationMode::Test);
        static::assertSame(DeclarationMode::Test, $flow->mode());

        $result = $this->live->gateway->deposit($this->live->tenantId, $flow);

        // Either documented answer settles the hosts, the test indicator and the shape of the flow; the reasons of a
        // rejection notice (a flow without raison sociale, an indicator URSSAF does not know) are in the sample.
        static::assertTrue($result instanceof ElectronicReceipt || $result instanceof RejectionNotice);
        static::assertNotSame('', $result->document()->bytes());
    }

    /**
     * @return list<ReturnReference>
     */
    private function listReturns(Instant $from, ?Instant $to): array
    {
        if (self::$listedOnce) {
            sleep(self::LIST_PAUSE_SECONDS);
        }
        self::$listedOnce = true;

        try {
            return $this->live->gateway->listReturns($this->live->tenantId, $from, $to);
        } catch (PollingConstraintViolated $refusal) {
            $retryAfter = $refusal->retryAfterSeconds();
            static::markTestSkipped(\sprintf(
                'URSSAF refused the polling (HTTP %d, Retry-After %s): run the contract tests again later.',
                $refusal->status(),
                $retryAfter === null ? 'none' : $retryAfter . ' s',
            ));
        }
    }

    private static function hoursAgo(int $hours): Instant
    {
        return Instant::fromDateTime((new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('PT' . $hours . 'H')));
    }

    /**
     * @param list<ReturnReference> $references
     */
    private static function assertDatesAreReadable(array $references): void
    {
        foreach ($references as $reference) {
            static::assertInstanceOf(
                Instant::class,
                $reference->publishedAt(),
                \sprintf('The publication "%s" is not read as yyyyMMddHHmmss (api-reference.md section 5.8).', $reference->publicationRaw()),
            );
        }
    }
}
