<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class ListReturnsCommandTest extends TestCase
{
    public function testItListsTheReturnsOfTheDefaultLookback(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $deposit = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');

        $command = $harness->command('urssaf:dpae:returns');
        $status = $command->execute([]);

        static::assertSame(0, $status);
        $rows = Output::rows($command->getDisplay());
        static::assertSame(['Flux', 'Nature code', 'Status code', 'Ops', 'Publication', 'Document'], $rows[0]);
        static::assertSame([$deposit->flowId()?->value(), '10', 'OK', '-', '20220301000000', 'yes'], $rows[1]);
        static::assertStringContainsString('1 return(s).', $command->getDisplay());
    }

    public function testTheRangeIsGivenWithIsoDates(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');

        $inside = $harness->command('urssaf:dpae:returns');
        $inside->execute(['--from' => '2022-02-28T00:00:00+00:00', '--to' => '2022-03-01T00:00:00+00:00']);
        $before = $harness->command('urssaf:dpae:returns');
        $before->execute(['--from' => '2022-02-01T00:00:00+00:00', '--to' => '2022-02-28T00:00:00+00:00']);
        $after = $harness->command('urssaf:dpae:returns');
        $after->execute(['--from' => '2022-03-01T00:00:01+00:00']);

        static::assertStringContainsString('1 return(s).', $inside->getDisplay());
        static::assertStringContainsString('0 return(s).', $before->getDisplay());
        static::assertStringContainsString('0 return(s).', $after->getDisplay());
    }

    public function testADefaultLookbackThatIsTooShortFindsNothing(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['workflow' => ['initial_lookback' => 'PT1S']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $harness->clock->modify('+1 hour');

        $command = $harness->command('urssaf:dpae:returns');
        $command->execute([]);

        static::assertStringContainsString('0 return(s).', $command->getDisplay());
    }

    public function testInvalidDatesAndRangesFail(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));

        $invalid = $harness->command('urssaf:dpae:returns');
        static::assertSame(1, $invalid->execute(['--from' => 'yesterday']));
        static::assertStringContainsString('The --from option must be an ISO 8601 date-time with an offset', $invalid->getDisplay());

        $invalidEnd = $harness->command('urssaf:dpae:returns');
        static::assertSame(1, $invalidEnd->execute(['--to' => '2022-03-01 00:00']));
        static::assertStringContainsString('The --to option must be an ISO 8601 date-time with an offset', $invalidEnd->getDisplay());

        $impossible = $harness->command('urssaf:dpae:returns');
        static::assertSame(1, $impossible->execute(['--from' => '2022-02-30T00:00:00+00:00']));

        $inverted = $harness->command('urssaf:dpae:returns');
        static::assertSame(1, $inverted->execute(['--from' => '2022-03-02T00:00:00+00:00', '--to' => '2022-03-01T00:00:00+00:00']));
        static::assertStringContainsString('The start of the range is after its end.', $inverted->getDisplay());
    }
}
