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
use Symfony\Component\HttpClient\Psr18Client;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\BundleConfiguration;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

final class BundleConfigurationTest extends TestCase
{
    public function testTheDriverDefaultsToDoctrineWhenTheOrmIsInstalled(): void
    {
        static::assertSame('doctrine', BundleConfiguration::defaultDriver());
    }

    public function testTheMinimalConfigurationCompiles(): void
    {
        $harness = Harness::bootDefault([]);

        static::assertInstanceOf(DepositDpaeHandler::class, $harness->get(DepositDpaeHandler::class));
        static::assertInstanceOf(Psr18Client::class, $harness->privateService(ServiceIds::PSR18_CLIENT, Psr18Client::class));
        static::assertInstanceOf(InMemoryTokenStore::class, $harness->privateService(TokenStore::class, TokenStore::class));
        static::assertInstanceOf(ImmediateTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
    }

    public function testTheMinimalConfigurationUsesTheDocumentedDefaults(): void
    {
        $harness = Harness::bootDefault([]);
        $configuration = $harness->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);
        $endpoints = $configuration->endpoints();
        $direct = ServiceCode::direct();
        $concentrator = ServiceCode::concentrator();

        static::assertSame(DeclarationMode::Test, $configuration->declarationMode());
        static::assertSame('https://mon.urssaf.fr', $endpoints->baseUri(DpaeService::Authentication, $concentrator));
        static::assertSame('https://depot.dpae-edi.urssaf.fr', $endpoints->baseUri(DpaeService::Deposit, $direct));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Consultation, $concentrator));
        static::assertSame('https://telechargement.dpae-edi.urssaf.fr:8443', $endpoints->baseUri(DpaeService::Download, $concentrator));
        static::assertSame(8443, $endpoints->concentratorPort());
        static::assertSame('UTF-8', $configuration->authenticationEncoding());
        static::assertSame('text/plain', $configuration->depositContentType());
        static::assertSame(ApiConfiguration::DEFAULT_MAX_FLOW_BYTES, $configuration->maxFlowBytes());
        static::assertSame(ApiConfiguration::DEFAULT_MAX_RESPONSE_BYTES, $configuration->maxResponseBytes());
        static::assertSame('Europe/Paris', $configuration->returnsTimeZone()->getName());
        static::assertEquals(TestIndicators::defaults(), $harness->privateService(TestIndicators::class, TestIndicators::class));
        static::assertSame(['tenant-1'], $harness->kernel->getContainer()->getParameter(UrssafDpaeBundle::PARAMETER_TENANTS));
    }

    public function testOnlyTheHandlersArePublic(): void
    {
        $harness = Harness::bootDefault([]);

        foreach ([DepositDpaeHandler::class, ListReturnsHandler::class, SynchronizeReturnsHandler::class, DownloadReturnHandler::class, ReconcileUnknownDepositsHandler::class] as $handler) {
            static::assertTrue($harness->has($handler), $handler);
        }
        static::assertFalse($harness->has(WorkflowPolicy::class));
        static::assertFalse($harness->has(Clock::class));
        static::assertTrue($harness->hasPrivate(Clock::class));
    }
}
