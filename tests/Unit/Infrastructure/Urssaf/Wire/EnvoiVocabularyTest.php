<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiVocabulary;

final class EnvoiVocabularyTest extends TestCase
{
    public function testTheDefaultsAreTheInferredNames(): void
    {
        $vocabulary = EnvoiVocabulary::defaults();

        static::assertSame('IdFluxDPAE', $vocabulary->flowId());
        static::assertSame(['DateGeneration', 'dateGeneration'], $vocabulary->dates());
        static::assertSame(['motif', 'motifRejet', 'MotifRejet'], $vocabulary->reasons());
    }

    public function testNamesAreTrimmed(): void
    {
        $vocabulary = EnvoiVocabulary::of(' Flux ', [' A ', 'B'], [' C ']);

        static::assertSame('Flux', $vocabulary->flowId());
        static::assertSame(['A', 'B'], $vocabulary->dates());
        static::assertSame(['C'], $vocabulary->reasons());
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>, string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'blank flow id' => [' ', ['a'], ['b'], 'envoi_flow_id.empty'];
        yield 'no date' => ['f', [], ['b'], 'envoi_dates.empty'];
        yield 'blank date' => ['f', [''], ['b'], 'envoi_dates.empty'];
        yield 'no reason' => ['f', ['a'], [], 'envoi_reasons.empty'];
        yield 'blank reason' => ['f', ['a'], ["\t"], 'envoi_reasons.empty'];
    }

    /**
     * @param list<string> $dates
     * @param list<string> $reasons
     */
    #[DataProvider('provideInvalid')]
    public function testItRefusesBlankNamesAndEmptyLists(string $flowId, array $dates, array $reasons, string $code): void
    {
        try {
            EnvoiVocabulary::of($flowId, $dates, $reasons);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
        }
    }
}
