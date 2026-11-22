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
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;

final class DpaeFlowModeTest extends TestCase
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

    public function testTheIndicatorsAreConfigurable(): void
    {
        $indicators = TestIndicators::of('REAL', 'TEST');

        static::assertSame(DeclarationMode::Real, DpaeFlow::fromXml(self::flowWith('REAL'), DeclarationMode::Real, $indicators)->mode());
        static::assertSame(DeclarationMode::Test, DpaeFlow::fromXml(self::flowWith('TEST'), DeclarationMode::Test, $indicators)->mode());
        $this->assertMismatch(self::flowWith('120'), DeclarationMode::Real, $indicators, 'real');
    }

    public function testARealExampleCannotBeDepositedAsATestFlow(): void
    {
        $this->assertMismatch(self::fixture(), DeclarationMode::Test, TestIndicators::defaults(), 'test');
    }

    public function testATestFlowCannotBeDepositedAsARealFlow(): void
    {
        $this->assertMismatch(self::flowWith('TST'), DeclarationMode::Real, TestIndicators::defaults(), 'real');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFlowsWithoutAUsableIndicator(): iterable
    {
        yield 'missing' => [self::flowWith(null)];
        yield 'empty' => [self::flowWith('')];
        yield 'surrounding spaces' => [self::flowWith(' TST ')];
        yield 'other case' => [self::flowWith('tst')];
        yield 'nested' => [
            '<FR_DUE_Upload><Group><FR_DUE_Upload.Test.Indicator>TST</FR_DUE_Upload.Test.Indicator></Group></FR_DUE_Upload>',
        ];
        yield 'prefixed' => [
            '<FR_DUE_Upload><p:FR_DUE_Upload.Test.Indicator xmlns:p="urn:x">TST</p:FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>',
        ];
    }

    #[DataProvider('provideFlowsWithoutAUsableIndicator')]
    public function testTheIndicatorIsComparedStrictlyAmongTheChildrenOfTheRoot(string $bytes): void
    {
        $this->assertMismatch($bytes, DeclarationMode::Test, TestIndicators::defaults(), 'test');
    }

    private function assertMismatch(string $bytes, DeclarationMode $mode, TestIndicators $indicators, string $modeValue): void
    {
        try {
            DpaeFlow::fromXml($bytes, $mode, $indicators);
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            $errors = $exception->result()->errors();
            static::assertCount(1, $errors);
            static::assertSame('flow.declaration_mode_mismatch', $errors[0]->code());
            static::assertSame('flow', $errors[0]->path());
            static::assertSame(['mode' => $modeValue], $errors[0]->params());
            static::assertSame('The test indicator of the flow does not match the declaration mode.', $errors[0]->message());
        }
    }
}
