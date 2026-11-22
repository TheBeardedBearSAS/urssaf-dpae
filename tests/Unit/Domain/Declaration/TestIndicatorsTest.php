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
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class TestIndicatorsTest extends TestCase
{
    public function testDefaultsAreTheInferredValues(): void
    {
        $indicators = TestIndicators::defaults();

        static::assertSame('120', $indicators->forMode(DeclarationMode::Real));
        static::assertSame('TST', $indicators->forMode(DeclarationMode::Test));
    }

    public function testValuesAreConfigurable(): void
    {
        $indicators = TestIndicators::of('R', 'T');

        static::assertSame('R', $indicators->forMode(DeclarationMode::Real));
        static::assertSame('T', $indicators->forMode(DeclarationMode::Test));
    }

    public function testValuesDifferingOnlyByCaseAreDistinct(): void
    {
        static::assertSame('tst', TestIndicators::of('TST', 'tst')->forMode(DeclarationMode::Test));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'empty real' => ['', 'TST', 'test_indicator_real.empty'];
        yield 'blank real' => [" \t", 'TST', 'test_indicator_real.empty'];
        yield 'empty test' => ['120', '', 'test_indicator_test.empty'];
        yield 'blank test' => ['120', "\n", 'test_indicator_test.empty'];
        yield 'same value' => ['120', '120', 'test_indicators.not_distinct'];
    }

    #[DataProvider('provideRefusals')]
    public function testUnusableValuesAreRefused(string $real, string $test, string $code): void
    {
        try {
            TestIndicators::of($real, $test);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
        }
    }
}
