<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceKind;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

final class BundleConfigurationOptionsTest extends TestCase
{
    public function testTheWorkflowPolicyDefaultsAreThePolicyDefaults(): void
    {
        $harness = Harness::bootDefault([]);

        static::assertEquals(WorkflowPolicy::defaults(), $harness->privateService(WorkflowPolicy::class, WorkflowPolicy::class));
    }

    public function testTheWorkflowPolicyIsConfigurable(): void
    {
        $harness = Harness::bootDefault(['workflow' => [
            'max_deposit_attempts' => 7,
            'stale_deposit_after' => 'PT20M',
            'reconciliation_skew' => 'PT6M',
            'reconciliation_window' => 'PT2H',
            'manual_review_after' => 'PT7H',
            'polling_overlap' => 'PT3H',
            'initial_lookback' => 'P8D',
            'polling_max_range' => 'P1D',
            'polling_retry_delay_seconds' => 90,
            'max_polling_constraint_retries' => 9,
            'auto_adopt' => false,
        ]]);
        $policy = $harness->privateService(WorkflowPolicy::class, WorkflowPolicy::class);

        static::assertSame(7, $policy->maxDepositAttempts());
        static::assertSame(20, $policy->staleDepositAfter()->i);
        static::assertSame(6, $policy->reconciliationSkew()->i);
        static::assertSame(2, $policy->reconciliationWindow()->h);
        static::assertSame(7, $policy->manualReviewAfter()->h);
        static::assertSame(3, $policy->pollingOverlap()->h);
        static::assertSame(8, $policy->initialLookback()->d);
        static::assertSame(1, $policy->pollingMaxRange()?->d);
        static::assertSame(90, $policy->pollingRetryDelaySeconds());
        static::assertSame(9, $policy->maxPollingConstraintRetries());
    }

    public function testTheApiOptionsAreConfigurable(): void
    {
        $harness = Harness::bootDefault([
            'declaration_mode' => 'real',
            'hosts' => ['deposit' => 'https://deposit.example.test/prefix/', 'authentication' => 'https://auth.example.test'],
            'concentrator_port' => 9443,
            'authentication_encoding' => 'ISO-8859-1',
            'deposit_content_type' => 'application/xml',
            'max_flow_bytes' => 1000,
            'max_response_bytes' => 2000,
            'returns_time_zone' => 'UTC',
            'test_indicator' => ['real' => 'REAL', 'test' => 'TEST'],
        ]);
        $configuration = $harness->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);
        $service = ServiceCode::concentrator();

        static::assertSame(DeclarationMode::Real, $configuration->declarationMode());
        static::assertSame('https://deposit.example.test/prefix', $configuration->endpoints()->baseUri(DpaeService::Deposit, $service));
        static::assertSame('https://auth.example.test', $configuration->endpoints()->baseUri(DpaeService::Authentication, $service));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr:9443', $configuration->endpoints()->baseUri(DpaeService::Consultation, $service));
        static::assertSame('ISO-8859-1', $configuration->authenticationEncoding());
        static::assertSame('application/xml', $configuration->depositContentType());
        static::assertSame(1000, $configuration->maxFlowBytes());
        static::assertSame(2000, $configuration->maxResponseBytes());
        static::assertSame('UTC', $configuration->returnsTimeZone()->getName());
        static::assertEquals(TestIndicators::of('REAL', 'TEST'), $harness->privateService(TestIndicators::class, TestIndicators::class));
    }

    public function testEmptyHostOverridesKeepTheDefaults(): void
    {
        $harness = Harness::bootDefault(['hosts' => ['deposit' => '', 'authentication' => null]]);
        $configuration = $harness->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);

        static::assertSame('https://depot.dpae-edi.urssaf.fr', $configuration->endpoints()->baseUri(DpaeService::Deposit, ServiceCode::direct()));
    }

    public function testTheTokenStoreCanBeProvidedByTheApplication(): void
    {
        $harness = Harness::bootDefault(['token_store' => ['service' => 'test.token_store']]);

        static::assertSame($harness->privateService('test.token_store', TokenStore::class), $harness->privateService(TokenStore::class, TokenStore::class));
    }

    public function testTheApplicationProvidesTheCredentialsAndThePersistence(): void
    {
        $harness = Harness::bootDefault([
            'credentials' => null,
            'credentials_provider' => 'test.credentials',
            'tenants' => ['t-9'],
            'persistence' => [
                'driver' => 'custom',
                'deposit_repository' => 'test.deposits',
                'return_repository' => 'test.returns',
                'sync_cursor_repository' => 'test.cursors',
                'return_content_store' => 'test.contents',
                'transaction_manager' => 'test.transactions',
            ],
        ]);

        static::assertSame($harness->privateService('test.credentials', CredentialsProvider::class), $harness->privateService(CredentialsProvider::class, CredentialsProvider::class));
        static::assertSame($harness->privateService('test.deposits', DepositRepository::class), $harness->privateService(DepositRepository::class, DepositRepository::class));
        static::assertSame(['t-9'], $harness->kernel->getContainer()->getParameter(UrssafDpaeBundle::PARAMETER_TENANTS));
    }

    public function testTenantsAreStringsEvenWhenTheyLookLikeNumbers(): void
    {
        $harness = Harness::bootDefault(['credentials' => ['42' => ['siret' => TestKernel::SIRET, 'nom' => 'Martin', 'prenom' => 'Claire', 'password' => TestKernel::SECRET, 'service' => 98]]]);

        static::assertSame(['tenant-1', '42'], $harness->kernel->getContainer()->getParameter(UrssafDpaeBundle::PARAMETER_TENANTS));
        $credentials = $harness->privateService(CredentialsProvider::class, CredentialsProvider::class)->forTenant(TenantId::fromString('42'));
        static::assertSame(ServiceKind::Concentrator, $credentials->service()->kind());
    }

    public function testAnUnknownServiceCodeIsPreserved(): void
    {
        $harness = Harness::bootDefault(['credentials' => ['tenant-1' => ['siret' => TestKernel::SIRET, 'nom' => 'Martin', 'prenom' => 'Claire', 'password' => TestKernel::SECRET, 'service' => 97]]]);
        $credentials = $harness->privateService(CredentialsProvider::class, CredentialsProvider::class)->forTenant(TenantId::fromString('tenant-1'));

        static::assertSame(97, $credentials->service()->value());
        static::assertNotInstanceOf(ServiceKind::class, $credentials->service()->kind());
    }

    public function testTheOutboxIsIgnoredUntilEnabled(): void
    {
        static::assertInstanceOf(IgnoredOutbox::class, Harness::bootDefault([])->privateService(Outbox::class, Outbox::class));
        static::assertInstanceOf(InMemoryOutbox::class, Harness::bootDefault(['persistence' => ['driver' => 'in_memory'], 'outbox' => ['enabled' => true]])->privateService(Outbox::class, Outbox::class));
    }
}
