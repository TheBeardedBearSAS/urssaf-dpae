<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;

/**
 * A gateway wired to a mock PSR-18 client, with every double the tests inspect: requests, token store, logger, clock.
 * The credentials are fictitious.
 */
final readonly class HttpStack
{
    public const TENANT = 'acme';
    public const MOTDEPASSE = 'Sup3r-Secret-Passw0rd';
    public const JETON = 'dG9rZW4tZml4dHVyZQ==';

    public Psr17Factory $factory;
    public InMemoryTokenStore $tokens;
    public RecordingLogger $logger;
    public DeclarantCredentials $credentials;

    private function __construct(
        public ClientInterface $client,
        public ApiConfiguration $configuration,
        public SteppingClock $clock,
        ServiceCode $service,
    ) {
        $this->factory = new Psr17Factory();
        $this->tokens = new InMemoryTokenStore();
        $this->logger = new RecordingLogger();
        $this->credentials = DeclarantCredentials::of(Siret::fromString('99999999999999'), 'John', 'Doe', self::MOTDEPASSE, $service);
    }

    public static function standard(ApiConfiguration $configuration, ServiceCode $service): self
    {
        return new self(new Client(), $configuration, new SteppingClock(5), $service);
    }

    public static function withClient(ClientInterface $client, ApiConfiguration $configuration, ServiceCode $service): self
    {
        return new self($client, $configuration, new SteppingClock(5), $service);
    }

    public static function withClock(SteppingClock $clock, ApiConfiguration $configuration, ServiceCode $service): self
    {
        return new self(new Client(), $configuration, $clock, $service);
    }

    public function mock(): Client
    {
        return $this->client instanceof Client ? $this->client : throw new LogicException('Not a mock client.');
    }

    public function tenant(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    /**
     * @param array<string, string> $headers
     */
    public function queue(int $status, string $body, array $headers): void
    {
        $response = $this->factory->createResponse($status)->withBody($this->factory->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->mock()->addResponse($response);
    }

    public function queueToken(): void
    {
        $this->queue(200, self::JETON, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values($this->mock()->getRequests());
    }

    public function gateway(): UrssafHttpGateway
    {
        return UrssafGatewayFactory::createWith(
            new HttpTransport($this->client, $this->factory, $this->factory),
            new InMemoryCredentialsProvider([self::TENANT => $this->credentials]),
            $this->tokens,
            $this->configuration,
            GatewayOptions::defaults()->withLogger($this->logger)->withClock($this->clock),
        );
    }
}
