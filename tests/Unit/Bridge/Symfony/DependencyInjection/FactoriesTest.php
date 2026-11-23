<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\DependencyInjection;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ApiConfigurationFactory;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ClockFactory;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\DeclarantCredentialsFactory;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\GatewayFactory;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\RecordingLogger;

final class FactoriesTest extends TestCase
{
    public function testTheApplicationClockIsUsedWhenThereIsOne(): void
    {
        $clock = new FixedClock(Samples::NOW);

        static::assertSame($clock, ClockFactory::psr($clock));
        static::assertInstanceOf(SystemClock::class, ClockFactory::psr(null));
    }

    public function testThePortReadsThePsrClock(): void
    {
        $port = ClockFactory::port(new FixedClock(Samples::NOW));

        static::assertEquals(new DateTimeImmutable(Samples::NOW), $port->now());
    }

    public function testTheCredentialsAreBuiltFromTheirParts(): void
    {
        $credentials = DeclarantCredentialsFactory::create('12345678901234', 'Martin', 'Claire', 'Passw0rd-fake', 97);

        static::assertSame('12345678901234', $credentials->siret()->value());
        static::assertSame('Martin', $credentials->nom());
        static::assertSame('Claire', $credentials->prenom());
        static::assertSame('Passw0rd-fake', $credentials->motdepasse());
        static::assertTrue($credentials->service()->equals(ServiceCode::fromInt(97)));
    }

    public function testInvalidCredentialsAreRefused(): void
    {
        $this->expectException(InvalidValue::class);

        DeclarantCredentialsFactory::create('123', 'Martin', 'Claire', 'Passw0rd-fake', 25);
    }

    public function testTheApiConfigurationKeepsTheDefaultHostsWhenNoneIsGiven(): void
    {
        $configuration = ApiConfigurationFactory::create(DeclarationMode::Test, [], 8443, 'UTF-8', 'text/plain', 10, 20, 'Europe/Paris');
        $direct = ServiceCode::direct();

        static::assertSame('https://depot.dpae-edi.urssaf.fr', $configuration->endpoints()->baseUri(DpaeService::Deposit, $direct));
        static::assertSame(DeclarationMode::Test, $configuration->declarationMode());
        static::assertSame(10, $configuration->maxFlowBytes());
        static::assertSame(20, $configuration->maxResponseBytes());
    }

    public function testTheHostOverridesAreApplied(): void
    {
        $configuration = ApiConfigurationFactory::create(
            DeclarationMode::Real,
            ['authentication' => 'https://auth.example.test', 'deposit' => '', 'consultation' => null, 'download' => 'https://download.example.test/prefix/'],
            9443,
            'ISO-8859-1',
            'application/xml',
            10,
            20,
            'UTC',
        );
        $concentrator = ServiceCode::concentrator();

        static::assertSame('https://auth.example.test', $configuration->endpoints()->baseUri(DpaeService::Authentication, $concentrator));
        static::assertSame('https://depot.dpae-edi.urssaf.fr:9443', $configuration->endpoints()->baseUri(DpaeService::Deposit, $concentrator));
        static::assertSame('https://consultation.dpae-edi.urssaf.fr:9443', $configuration->endpoints()->baseUri(DpaeService::Consultation, $concentrator));
        static::assertSame('https://download.example.test/prefix', $configuration->endpoints()->baseUri(DpaeService::Download, $concentrator));
        static::assertSame(DeclarationMode::Real, $configuration->declarationMode());
        static::assertSame('ISO-8859-1', $configuration->authenticationEncoding());
        static::assertSame('application/xml', $configuration->depositContentType());
        static::assertSame('UTC', $configuration->returnsTimeZone()->getName());
    }

    public function testAnInvalidOptionIsRefused(): void
    {
        $this->expectException(InvalidValue::class);

        ApiConfigurationFactory::create(DeclarationMode::Test, ['deposit' => 'not a uri'], 8443, 'UTF-8', 'text/plain', 10, 20, 'UTC');
    }

    public function testTheGatewayOptionsCarryTheClockAndTheLoggerWhenThereIsOne(): void
    {
        $clock = new FixedClock(Samples::NOW);
        $logger = new NullLogger();

        $with = GatewayFactory::options($clock, $logger);
        $without = GatewayFactory::options($clock, null);

        static::assertSame($clock, $with->clock());
        static::assertSame($logger, $with->logger());
        static::assertSame($clock, $without->clock());
        static::assertNull($without->logger());
    }

    public function testTheProbeTokenProviderUsesAStoreOfItsOwn(): void
    {
        $stack = HttpStack::standard(ApiConfigurationFactory::create(DeclarationMode::Test, [], 8443, 'UTF-8', 'text/plain', 10, 20, 'UTC'), ServiceCode::direct());
        $transport = new HttpTransport($stack->client, $stack->factory, $stack->factory);
        $logger = new RecordingLogger();
        $stack->queue(200, HttpStack::JETON, ['Content-Type' => 'application/octet-stream']);
        $stack->queue(200, HttpStack::JETON, ['Content-Type' => 'application/octet-stream']);
        $provider = GatewayFactory::probeTokenProvider($transport, $stack->clock, $stack->configuration, $logger);

        $first = $provider->token($stack->tenant(), $stack->credentials, Operation::Authenticate);
        $provider->token($stack->tenant(), $stack->credentials, Operation::Authenticate);
        static::assertCount(1, $stack->mock()->getRequests());
        $provider->invalidate($stack->tenant(), $stack->credentials);
        $provider->token($stack->tenant(), $stack->credentials, Operation::Authenticate);

        static::assertSame(HttpStack::JETON, $first->reveal());
        static::assertCount(2, $stack->mock()->getRequests());
        static::assertNotSame([], $logger->records());
    }

    public function testTheProbeTokenProviderWorksWithoutALogger(): void
    {
        $stack = HttpStack::standard(ApiConfigurationFactory::create(DeclarationMode::Test, [], 8443, 'ISO-8859-1', 'text/plain', 10, 20, 'UTC'), ServiceCode::direct());
        $stack->queue(200, HttpStack::JETON, ['Content-Type' => 'application/octet-stream']);
        $provider = GatewayFactory::probeTokenProvider(new HttpTransport($stack->client, $stack->factory, $stack->factory), $stack->clock, $stack->configuration, null);

        static::assertSame(HttpStack::JETON, $provider->token($stack->tenant(), $stack->credentials, Operation::Authenticate)->reveal());
        static::assertStringContainsString('ISO-8859-1', (string) $stack->mock()->getRequests()[0]->getBody());
    }
}
