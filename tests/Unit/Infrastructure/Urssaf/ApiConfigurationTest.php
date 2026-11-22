<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;

final class ApiConfigurationTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $configuration = ApiConfiguration::defaults();

        static::assertSame(
            'https://depot.dpae-edi.urssaf.fr',
            $configuration->endpoints()->baseUri(DpaeService::Deposit, ServiceCode::direct()),
        );
        static::assertSame('UTF-8', $configuration->authenticationEncoding());
        static::assertSame('text/plain', $configuration->depositContentType());
        static::assertSame(100_000_000, $configuration->maxFlowBytes());
        static::assertSame(100_000_000, $configuration->maxResponseBytes());
        static::assertSame('Europe/Paris', $configuration->returnsTimeZone()->getName());
        static::assertSame(DeclarationMode::Test, $configuration->declarationMode());
    }

    public function testEachSettingChangesOnlyItself(): void
    {
        $endpoints = ServiceEndpoints::defaults()->withConcentratorPort(9443);
        $zone = new DateTimeZone('UTC');
        $configuration = ApiConfiguration::defaults()
            ->withEndpoints($endpoints)
            ->withAuthenticationEncoding('ISO-8859-1')
            ->withDepositContentType('application/xml')
            ->withMaxFlowBytes(10)
            ->withMaxResponseBytes(20)
            ->withReturnsTimeZone($zone)
            ->withDeclarationMode(DeclarationMode::Real);

        static::assertSame($endpoints, $configuration->endpoints());
        static::assertSame('ISO-8859-1', $configuration->authenticationEncoding());
        static::assertSame('application/xml', $configuration->depositContentType());
        static::assertSame(10, $configuration->maxFlowBytes());
        static::assertSame(20, $configuration->maxResponseBytes());
        static::assertSame($zone, $configuration->returnsTimeZone());
        static::assertSame(DeclarationMode::Real, $configuration->declarationMode());
    }

    public function testEachSettingKeepsTheOthers(): void
    {
        $endpoints = ServiceEndpoints::defaults()->withConcentratorPort(9443);
        $zone = new DateTimeZone('UTC');
        $base = ApiConfiguration::defaults()
            ->withEndpoints($endpoints)
            ->withAuthenticationEncoding('ISO-8859-1')
            ->withDepositContentType('application/xml')
            ->withMaxFlowBytes(10)
            ->withMaxResponseBytes(20)
            ->withReturnsTimeZone($zone)
            ->withDeclarationMode(DeclarationMode::Real);

        foreach ([
            $base->withEndpoints($endpoints),
            $base->withAuthenticationEncoding('ISO-8859-1'),
            $base->withDepositContentType('application/xml'),
            $base->withMaxFlowBytes(10),
            $base->withMaxResponseBytes(20),
            $base->withReturnsTimeZone($zone),
            $base->withDeclarationMode(DeclarationMode::Real),
        ] as $configuration) {
            static::assertSame($endpoints, $configuration->endpoints());
            static::assertSame('ISO-8859-1', $configuration->authenticationEncoding());
            static::assertSame('application/xml', $configuration->depositContentType());
            static::assertSame(10, $configuration->maxFlowBytes());
            static::assertSame(20, $configuration->maxResponseBytes());
            static::assertSame($zone, $configuration->returnsTimeZone());
            static::assertSame(DeclarationMode::Real, $configuration->declarationMode());
        }
    }

    public function testTheLimitsCanBeDisabled(): void
    {
        $configuration = ApiConfiguration::defaults()->withMaxFlowBytes(null)->withMaxResponseBytes(null);

        static::assertNull($configuration->maxFlowBytes());
        static::assertNull($configuration->maxResponseBytes());
        static::assertSame(1, ApiConfiguration::defaults()->withMaxFlowBytes(1)->maxFlowBytes());
        static::assertSame(1, ApiConfiguration::defaults()->withMaxResponseBytes(1)->maxResponseBytes());
    }

    public function testALimitMustBePositive(): void
    {
        foreach ([0, -1] as $bytes) {
            $this->assertViolation(
                static fn(): ApiConfiguration => ApiConfiguration::defaults()->withMaxFlowBytes($bytes),
                'max_flow_bytes.not_positive',
            );
            $this->assertViolation(
                static fn(): ApiConfiguration => ApiConfiguration::defaults()->withMaxResponseBytes($bytes),
                'max_response_bytes.not_positive',
            );
        }
    }

    #[DataProvider('provideInvalidContentTypes')]
    public function testTheDepositContentTypeMustBeAHeaderValue(string $contentType): void
    {
        $this->assertViolation(
            static fn(): ApiConfiguration => ApiConfiguration::defaults()->withDepositContentType($contentType),
            'deposit_content_type.invalid_format',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidContentTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'trailing newline' => ["text/plain\n"];
        yield 'leading space' => [' text/plain'];
        yield 'trailing space' => ['text/plain '];
        yield 'line break' => ["text/plain\r\nX-Evil: 1"];
        yield 'tab' => ["text/plain;\tcharset=x"];
        yield 'non ascii' => ['text/plaïn'];
    }

    public function testTheDepositContentTypeMayCarryParameters(): void
    {
        $configuration = ApiConfiguration::defaults()->withDepositContentType('text/plain; charset=ISO-8859-1');

        static::assertSame('text/plain; charset=ISO-8859-1', $configuration->depositContentType());
    }

    /**
     * @param callable(): mixed $action
     * @param-immediately-invoked-callable $action
     */
    private function assertViolation(callable $action, string $code): void
    {
        try {
            $action();
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }
}
