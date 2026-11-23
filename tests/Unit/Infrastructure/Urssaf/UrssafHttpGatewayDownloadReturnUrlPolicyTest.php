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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayDownloadReturnUrlPolicyTest extends TestCase
{
    private const DOWNLOAD = 'https://telechargement.dpae-edi.urssaf.fr';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function reference(string $flowId, ?string $returnId, ?string $url): ReturnReference
    {
        return ReturnReference::fromListing(
            FlowId::fromString($flowId),
            10,
            'OK',
            '20220130083000',
            '20220130082500',
            null,
            null,
            $returnId,
            null,
            null,
            $url,
        );
    }

    private static function download(HttpStack $stack, ReturnReference $return): ReturnContent
    {
        return $stack->gateway()->downloadReturn($stack->tenant(), $return);
    }

    #[DataProvider('provideHostileUrls')]
    public function testAUrlOutsideTheDownloadOriginIsNeverFollowedAndTheTokenNeverLeaves(string $url): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<a/>', ['Content-Type' => 'application/xml']);

        self::download($stack, self::reference('F1', '5', $url));

        $requests = $stack->requests();
        static::assertCount(2, $requests);
        foreach ($requests as $request) {
            static::assertStringStartsWith('https://', (string) $request->getUri());
            static::assertContains($request->getUri()->getHost(), ['mon.urssaf.fr', 'telechargement.dpae-edi.urssaf.fr']);
        }
        static::assertSame(self::DOWNLOAD . '/telecharger-retour/1.0/F1/5', (string) $requests[1]->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHostileUrls(): iterable
    {
        yield 'another host' => ['https://evil.test/telecharger-retour/1.0/F1/5'];
        yield 'suffix host' => ['https://telechargement.dpae-edi.urssaf.fr.evil.test/x'];
        yield 'userinfo' => ['https://telechargement.dpae-edi.urssaf.fr@evil.test/x'];
        yield 'userinfo on the good host' => ['https://evil.test@telechargement.dpae-edi.urssaf.fr/x'];
        yield 'http downgrade' => ['http://telechargement.dpae-edi.urssaf.fr/x'];
        yield 'another port' => ['https://telechargement.dpae-edi.urssaf.fr:8443/x'];
        yield 'relative' => ['/telecharger-retour/1.0/F1/5'];
        yield 'other scheme' => ['ftp://telechargement.dpae-edi.urssaf.fr/x'];
        yield 'backslash' => ['https://evil.test\\@telechargement.dpae-edi.urssaf.fr/x'];
    }

    #[DataProvider('provideUnusableReturns')]
    public function testAReturnWithoutAUsableDocumentIsNotDownloadableAndNothingIsSent(?string $returnId, ?string $url, ?string $origin): void
    {
        $stack = self::stack();

        $exception = Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', $returnId, $url)));

        static::assertInstanceOf(ReturnNotDownloadable::class, $exception);
        static::assertSame($origin, $exception->urlOrigin());
        static::assertSame([], $stack->requests());
    }

    /**
     * @return iterable<string, array{string|null, string|null, string|null}>
     */
    public static function provideUnusableReturns(): iterable
    {
        yield 'no url, no id' => [null, null, null];
        yield 'foreign url, no id' => [null, 'https://Evil.test/x?token=1', 'https://evil.test:443'];
        yield 'foreign url with a port, no id' => [null, 'http://evil.test:8080/x', 'http://evil.test:8080'];
        yield 'url with userinfo, no id' => [null, 'https://telechargement.dpae-edi.urssaf.fr@evil.test/x', 'unparsable'];
        yield 'relative url, no id' => [null, '/telecharger-retour/1.0/F1/5', 'unparsable'];
    }

    public function testALegacyIdOverTenCharactersIsRefusedBeforeAnyCall(): void
    {
        $stack = self::stack();

        $exception = Thrown::by(static fn(): ReturnContent => self::download($stack, self::reference('F1', '12345678901', null)));

        static::assertInstanceOf(InvalidValue::class, $exception);
        static::assertSame('return_id.too_long', $exception->violationCode());
        static::assertSame([], $stack->requests());
    }

    public function testAnUnknownTenantSendsNothing(): void
    {
        $stack = self::stack();

        $exception = Thrown::by(static fn(): ReturnContent => $stack->gateway()->downloadReturn(
            TenantId::fromString('nobody'),
            self::reference('F1', '1', null),
        ));

        static::assertInstanceOf(RecordNotFound::class, $exception);
        static::assertSame([], $stack->requests());
    }
}
