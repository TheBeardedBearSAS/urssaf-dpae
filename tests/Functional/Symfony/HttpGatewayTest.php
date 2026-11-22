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
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Responses;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\RecordingLogger;

/**
 * The HTTP gateway of the container: the four hosts (the authentication one included), the concentrator port, the token
 * kept in memory, nothing secret in the logs.
 */
final class HttpGatewayTest extends TestCase
{
    private Workspace $files;

    protected function setUp(): void
    {
        $this->files = new Workspace();
    }

    public function testADepositAuthenticatesThenDepositsOnThePublishedHosts(): void
    {
        $harness = Harness::bootDefault([]);
        $this->queueReceipt($harness);

        $status = $this->deposit($harness, 'ref-1');

        static::assertSame(0, $status);
        $requests = $harness->client->getRequests();
        static::assertCount(2, $requests);
        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $requests[0]->getUri());
        static::assertSame('https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0', (string) $requests[1]->getUri());
        static::assertSame('DPAELogin jeton=' . Responses::TOKEN, $requests[1]->getHeaderLine('Authorization'));
        static::assertSame('gzip', $requests[1]->getHeaderLine('Content-Encoding'));
        static::assertSame('text/plain', $requests[1]->getHeaderLine('Content-Type'));
        static::assertSame(Flows::xml(DeclarationMode::Test, 'a'), gzdecode((string) $requests[1]->getBody()));
        static::assertStringContainsString('<siret>' . TestKernel::SIRET . '</siret>', (string) $requests[0]->getBody());
    }

    public function testTheTokenIsKeptInMemoryForTheNextDeposit(): void
    {
        $harness = Harness::bootDefault([]);
        $this->queueReceipt($harness);
        $harness->client->addResponse(Responses::response(200, $this->receipt('FICTIF0000000000000002'), 'application/xml'));

        static::assertSame(0, $this->deposit($harness, 'ref-1'));
        static::assertSame(0, $this->deposit($harness, 'ref-2', 'b'));

        $uris = array_map(static fn(RequestInterface $request): string => (string) $request->getUri(), $harness->client->getRequests());
        static::assertSame([
            'https://mon.urssaf.fr/authentifier_dpae',
            'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0',
            'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0',
        ], $uris);
    }

    public function testTheLogsCarryNeitherThePasswordNorTheToken(): void
    {
        $harness = Harness::bootDefault([]);
        $this->queueReceipt($harness);

        static::assertSame(0, $this->deposit($harness, 'ref-1'));

        $logger = $harness->privateService('logger', RecordingLogger::class);
        static::assertNotSame([], $logger->records());
        static::assertStringNotContainsString(TestKernel::SECRET, $logger->dump());
        static::assertStringNotContainsString(Responses::TOKEN, $logger->dump());
    }

    public function testTheGatewayOfTheContainerIsTheHttpGateway(): void
    {
        $harness = Harness::bootDefault([]);

        static::assertInstanceOf(DpaeGateway::class, $harness->privateService(DpaeGateway::class, DpaeGateway::class));
    }

    private function deposit(Harness $harness, string $reference, string $variant = 'a'): int
    {
        $file = $this->files->write($reference . '.xml', Flows::xml(DeclarationMode::Test, $variant));

        return $harness->command('urssaf:dpae:deposit')->execute(['file' => $file, '--mode' => 'test', '--reference' => $reference]);
    }

    private function queueReceipt(Harness $harness): void
    {
        $harness->client->addResponse(Responses::token());
        $harness->client->addResponse(Responses::response(200, $this->receipt('FICTIF0000000000000001'), 'application/xml'));
    }

    private function receipt(string $flowId): string
    {
        return '<envoi><IdFluxDPAE>' . $flowId . '</IdFluxDPAE></envoi>';
    }
}
