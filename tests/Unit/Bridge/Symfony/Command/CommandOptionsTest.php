<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\CommandOptions;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class CommandOptionsTest extends TestCase
{
    public function testAnInvalidDateIsAnInvalidOptionCausedByTheDomainFailure(): void
    {
        $input = new ArrayInput(['--from' => 'yesterday'], new InputDefinition([new InputOption('from', null, InputOption::VALUE_REQUIRED)]));

        try {
            CommandOptions::instant($input, 'from');
            static::fail('An invalid option was expected.');
        } catch (InvalidOptionException $failure) {
            static::assertSame(0, $failure->getCode());
            static::assertInstanceOf(InvalidValue::class, $failure->getPrevious());
            static::assertSame('The --from option must be an ISO 8601 date-time with an offset, such as 2026-11-22T08:30:00+01:00.', $failure->getMessage());
        }
    }

    public function testAMissingDateIsNull(): void
    {
        $input = new ArrayInput([], new InputDefinition([new InputOption('from', null, InputOption::VALUE_REQUIRED)]));

        static::assertNull(CommandOptions::instant($input, 'from'));
    }

    public function testTheLimitDefaultsAndMustBeAPositiveInteger(): void
    {
        $definition = new InputDefinition([new InputOption('limit', null, InputOption::VALUE_REQUIRED)]);

        static::assertSame(100, CommandOptions::limit(new ArrayInput([], $definition), 100));
        static::assertSame(1, CommandOptions::limit(new ArrayInput(['--limit' => '1'], $definition), 100));
        static::assertSame(250, CommandOptions::limit(new ArrayInput(['--limit' => '250'], $definition), 100));
        foreach (['0', '-1', '1.5', 'many', ''] as $invalid) {
            try {
                CommandOptions::limit(new ArrayInput(['--limit' => $invalid], $definition), 100);
                static::fail('"' . $invalid . '" must be refused.');
            } catch (InvalidOptionException $failure) {
                static::assertSame('The --limit option must be a positive integer.', $failure->getMessage());
            }
        }
    }
}
