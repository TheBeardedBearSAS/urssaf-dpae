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
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;

/**
 * What the bundle declares, before the container optimises it away.
 */
final class ServiceDefinitionsTest extends TestCase
{
    public function testTheHttpClientNeverFollowsARedirect(): void
    {
        $definition = (new TestKernel([], false, false, false))->definitions()->getDefinition(ServiceIds::SYMFONY_HTTP_CLIENT);

        static::assertSame([HttpClient::class, 'create'], $definition->getFactory());
        static::assertSame([['timeout' => 30.0, 'max_redirects' => 0]], $definition->getArguments());
    }

    public function testTheTimeoutOfTheHttpClientIsConfigurable(): void
    {
        $definition = (new TestKernel(['http_timeout' => 5.5], false, false, false))->definitions()->getDefinition(ServiceIds::SYMFONY_HTTP_CLIENT);

        static::assertSame([['timeout' => 5.5, 'max_redirects' => 0]], $definition->getArguments());
    }

    public function testThePsr18ClientWrapsTheHttpClient(): void
    {
        $definition = (new TestKernel([], false, false, false))->definitions()->getDefinition(ServiceIds::PSR18_CLIENT);

        static::assertSame(Psr18Client::class, $definition->getClass());
        $arguments = $definition->getArguments();
        static::assertCount(1, $arguments);
        static::assertInstanceOf(Reference::class, $arguments[0]);
        static::assertSame(ServiceIds::SYMFONY_HTTP_CLIENT, (string) $arguments[0]);
    }

    public function testTheLogsOfThePackageHaveTheirOwnChannel(): void
    {
        $definitions = (new TestKernel([], false, false, false))->definitions();

        static::assertSame([['channel' => 'urssaf_dpae']], $definitions->getDefinition(FailureReporter::class)->getTag('monolog.logger'));
        static::assertSame([['channel' => 'urssaf_dpae']], $definitions->getDefinition(ServiceIds::GATEWAY_OPTIONS)->getTag('monolog.logger'));
        static::assertSame([['channel' => 'urssaf_dpae']], $definitions->getDefinition(ServiceIds::PROBE_TOKEN_PROVIDER)->getTag('monolog.logger'));
        static::assertSame([], $definitions->getDefinition(DpaeGateway::class)->getTag('monolog.logger'));
    }

    public function testEveryServiceThatReceivesTheLoggerIsOnTheChannelOfThePackage(): void
    {
        $definitions = (new TestKernel([], false, false, false))->definitions();

        $withLogger = [];
        foreach ($definitions->getDefinitions() as $id => $definition) {
            $isOfThePackage = str_starts_with($id, 'urssaf_dpae.') || str_starts_with($id, 'TheBeardedBear\\UrssafDpae\\');
            if (!$isOfThePackage) {
                continue;
            }
            foreach ($definition->getArguments() as $argument) { // @mago-expect analysis:mixed-assignment Service arguments are untyped; narrowed by the instanceof below.
                if (!$argument instanceof Reference || (string) $argument !== 'logger') {
                    continue;
                }
                $withLogger[] = $id;
                static::assertSame([['channel' => 'urssaf_dpae']], $definition->getTag('monolog.logger'), $id);
            }
        }
        static::assertContains(ServiceIds::GATEWAY_OPTIONS, $withLogger);
        static::assertContains(ServiceIds::PROBE_TOKEN_PROVIDER, $withLogger);
        static::assertContains(FailureReporter::class, $withLogger);
    }

    public function testTheReservedNamesFollowTheCoexistencePrefix(): void
    {
        foreach ([ServiceIds::SYMFONY_HTTP_CLIENT, ServiceIds::PSR18_CLIENT, ServiceIds::HTTP_TRANSPORT, ServiceIds::PSR_CLOCK, ServiceIds::API_CONFIGURATION, ServiceIds::GATEWAY_OPTIONS, ServiceIds::INSTALLATION_MODE, ServiceIds::PROBE_TOKEN_PROVIDER] as $id) {
            static::assertStringStartsWith('urssaf_dpae.', $id);
        }
    }
}
