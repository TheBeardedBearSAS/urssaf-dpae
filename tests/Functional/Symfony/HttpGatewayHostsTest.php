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
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Responses;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;

/**
 * The HTTP gateway of the container: the four hosts (the authentication one included), the concentrator port, the token
 * kept in memory, nothing secret in the logs.
 */
final class HttpGatewayHostsTest extends TestCase
{
    private Workspace $files;

    protected function setUp(): void
    {
        $this->files = new Workspace();
    }

    public function testAConcentratorGetsThePortExceptOnTheAuthenticationHost(): void
    {
        $harness = Harness::bootDefault(['credentials' => ['tenant-1' => ['service' => 98]]]);
        $this->queueReceipt($harness);

        static::assertSame(0, $this->deposit($harness, 'ref-1'));

        $requests = $harness->client->getRequests();
        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $requests[0]->getUri());
        static::assertSame('https://depot.dpae-edi.urssaf.fr:8443/deposer-dsn/1.0', (string) $requests[1]->getUri());
    }

    public function testEveryHostIncludingTheAuthenticationOneIsOverridableAndUsedAsIs(): void
    {
        $harness = Harness::bootDefault([
            'hosts' => ['authentication' => 'http://mock.test:1080/auth', 'deposit' => 'http://mock.test:1080/deposit/', 'consultation' => 'http://mock.test:1080/consultation', 'download' => 'http://mock.test:1080/download'],
            'credentials' => ['tenant-1' => ['service' => 98]],
        ]);
        $this->queueReceipt($harness);

        static::assertSame(0, $this->deposit($harness, 'ref-1'));

        $requests = $harness->client->getRequests();
        static::assertSame('http://mock.test:1080/auth/authentifier_dpae', (string) $requests[0]->getUri());
        static::assertSame('http://mock.test:1080/deposit/deposer-dsn/1.0', (string) $requests[1]->getUri());
    }

    public function testTheListingAndTheDownloadUseTheirOwnHosts(): void
    {
        $harness = Harness::bootDefault([
            'hosts' => ['consultation' => 'http://mock.test:1080/consultation', 'download' => 'http://mock.test:1080/download'],
        ]);
        $harness->client->addResponse(Responses::token());
        $harness->client->addResponse(Responses::response(200, '<retours/>', 'application/xml'));

        $command = $harness->command('urssaf:dpae:returns');
        $status = $command->execute(['--from' => '2022-02-28T00:00:00+01:00']);

        static::assertSame(0, $status);
        $requests = $harness->client->getRequests();
        static::assertStringStartsWith('http://mock.test:1080/consultation/lister-retours-declarant/1.0/', (string) $requests[1]->getUri());
        static::assertStringContainsString('0 return(s).', $command->getDisplay());
    }

    public function testTheAuthenticationEncodingIsConfigurable(): void
    {
        $harness = Harness::bootDefault(['authentication_encoding' => 'ISO-8859-1']);
        $this->queueReceipt($harness);

        static::assertSame(0, $this->deposit($harness, 'ref-1'));

        static::assertStringContainsString('encoding="ISO-8859-1"', (string) $harness->client->getRequests()[0]->getBody());
    }

    public function testTheContentTypeOfTheDepositIsConfigurable(): void
    {
        $harness = Harness::bootDefault(['deposit_content_type' => 'application/xml']);
        $this->queueReceipt($harness);

        static::assertSame(0, $this->deposit($harness, 'ref-1'));

        static::assertSame('application/xml', $harness->client->getRequests()[1]->getHeaderLine('Content-Type'));
    }

    private function deposit(Harness $harness, string $reference): int
    {
        $file = $this->files->write($reference . '.xml', Flows::xml(DeclarationMode::Test, 'a'));

        return $harness->command('urssaf:dpae:deposit')->execute(['file' => $file, '--mode' => 'test', '--reference' => $reference]);
    }

    private function queueReceipt(Harness $harness): void
    {
        $harness->client->addResponse(Responses::token());
        $harness->client->addResponse(Responses::response(200, '<envoi><IdFluxDPAE>FICTIF0000000000000001</IdFluxDPAE></envoi>', 'application/xml'));
    }
}
