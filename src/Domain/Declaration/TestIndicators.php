<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Value of `FR_DUE_Upload.Test.Indicator` expected for each {@see DeclarationMode} (ADR 0003).
 *
 * The defaults (`Real` = `120`, `Test` = `TST`) are inferred from the `C_VERSION` rule and the official example
 * (api-reference.md §3.3, open point §5.2), hence configurable. The two values must be non-blank and different, or the
 * guard of {@see DpaeFlow::fromXml()} would be void.
 */
final readonly class TestIndicators
{
    public const DEFAULT_REAL = '120';
    public const DEFAULT_TEST = 'TST';

    private function __construct(
        private string $real,
        private string $test,
    ) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_REAL, self::DEFAULT_TEST);
    }

    /**
     * @throws InvalidValue `test_indicator_real.empty`, `test_indicator_test.empty` or `test_indicators.not_distinct`
     */
    public static function of(string $real, string $test): self
    {
        if (trim($real) === '') {
            throw InvalidValue::empty('test_indicator_real');
        }
        if (trim($test) === '') {
            throw InvalidValue::empty('test_indicator_test');
        }
        if ($real === $test) {
            throw InvalidValue::because(
                'test_indicators.not_distinct',
                'Invalid test_indicators: the real and the test indicators must differ.',
            );
        }

        return new self($real, $test);
    }

    public function forMode(DeclarationMode $mode): string
    {
        return $mode === DeclarationMode::Real ? $this->real : $this->test;
    }
}
