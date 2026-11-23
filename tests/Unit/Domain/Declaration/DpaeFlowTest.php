<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;

final class DpaeFlowTest extends TestCase
{
    private static function fixture(): string
    {
        $bytes = file_get_contents(__DIR__ . '/../../../Fixtures/wire/deposit_request_minimal.xml');
        static::assertIsString($bytes);

        return $bytes;
    }

    private static function flowWith(?string $indicator): string
    {
        return $indicator === null
            ? '<FR_DUE_Upload><Other>x</Other></FR_DUE_Upload>'
            : '<FR_DUE_Upload><FR_DUE_Upload.Test.Indicator>' . $indicator . '</FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>';
    }

    public function testTheOfficialExampleIsAnAcceptedRealFlowKeptVerbatim(): void
    {
        $bytes = self::fixture();

        $flow = DpaeFlow::fromXml($bytes, DeclarationMode::Real, TestIndicators::defaults());

        static::assertSame($bytes, $flow->bytes());
        static::assertSame(DeclarationMode::Real, $flow->mode());
        static::assertSame(hash('sha256', $bytes), $flow->fingerprint()->value());
    }

    public function testATestFlowIsAcceptedInTestMode(): void
    {
        $flow = DpaeFlow::fromXml(self::flowWith('TST'), DeclarationMode::Test, TestIndicators::defaults());

        static::assertSame(DeclarationMode::Test, $flow->mode());
    }

    public function testTheIndicatorTextIgnoresCommentsAndSiblingText(): void
    {
        $flow = DpaeFlow::fromXml(
            '<FR_DUE_Upload>text<!-- c --><FR_DUE_Upload.Test.Indicator>TST</FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>',
            DeclarationMode::Test,
            TestIndicators::defaults(),
        );

        static::assertSame(DeclarationMode::Test, $flow->mode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedPrefixes(): iterable
    {
        yield 'utf-8 byte order mark' => ["\xEF\xBB\xBF"];
        yield 'space' => [' '];
        yield 'tab' => ["\t"];
        yield 'carriage return' => ["\r"];
        yield 'line feed' => ["\n"];
        yield 'mark then whitespace' => ["\xEF\xBB\xBF \r\n"];
    }

    #[DataProvider('provideAcceptedPrefixes')]
    public function testLeadingBomAndWhitespaceAreAcceptedAndKept(string $prefix): void
    {
        $bytes = $prefix . self::flowWith('TST');

        static::assertSame($bytes, DpaeFlow::fromXml($bytes, DeclarationMode::Test, TestIndicators::defaults())->bytes());
    }

    public function testIso88591BytesAreAccepted(): void
    {
        $bytes = '<?xml version="1.0" encoding="ISO-8859-1" ?><FR_DUE_Upload><N>' . "\xE9" . '</N>'
            . '<FR_DUE_Upload.Test.Indicator>TST</FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>';

        static::assertSame($bytes, DpaeFlow::fromXml($bytes, DeclarationMode::Test, TestIndicators::defaults())->bytes());
    }

    public function testDebugInfoNeverShowsTheFlow(): void
    {
        $flow = DpaeFlow::fromXml(self::fixture(), DeclarationMode::Real, TestIndicators::defaults());

        static::assertSame(
            ['mode' => DeclarationMode::Real, 'fingerprint' => hash('sha256', self::fixture()), 'size' => \strlen(self::fixture())],
            $flow->__debugInfo(),
        );
        static::assertStringNotContainsString('99999999999999', print_r($flow, true));
    }
}
