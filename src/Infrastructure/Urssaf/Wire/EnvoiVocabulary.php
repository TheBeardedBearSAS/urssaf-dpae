<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Element or attribute names looked up in the answer of the deposit (schema `envoi`, ADR 0005).
 *
 * The XML form of `envoi` is not published (api-reference.md §5.9): these names are inferred, not attested, so a host
 * that learns the real ones overrides them instead of waiting for a release. Only the Swagger names `flux`, `id` and
 * `siret` (under `declarant`) are fixed in the reader.
 */
final readonly class EnvoiVocabulary
{
    /**
     * @param non-empty-string       $flowId  name of the flow id
     * @param non-empty-list<string> $dates   names of the generation date, in order of preference
     * @param non-empty-list<string> $reasons names of the reject reasons
     */
    private function __construct(
        private string $flowId,
        private array $dates,
        private array $reasons,
    ) {}

    public static function defaults(): self
    {
        return new self('IdFluxDPAE', ['DateGeneration', 'dateGeneration'], ['motif', 'motifRejet', 'MotifRejet']);
    }

    /**
     * @param list<string> $dates   names of the generation date, in order of preference
     * @param list<string> $reasons names of the reject reasons
     *
     * @throws InvalidValue `envoi_flow_id.empty`, `envoi_dates.empty` or `envoi_reasons.empty` when a name is blank or
     *                      a list is empty
     */
    public static function of(string $flowId, array $dates, array $reasons): self
    {
        return new self(
            self::name('envoi_flow_id', $flowId),
            self::names('envoi_dates', $dates),
            self::names('envoi_reasons', $reasons),
        );
    }

    /**
     * @return non-empty-string
     */
    public function flowId(): string
    {
        return $this->flowId;
    }

    /**
     * @return non-empty-list<string>
     */
    public function dates(): array
    {
        return $this->dates;
    }

    /**
     * @return non-empty-list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * @return non-empty-string
     */
    private static function name(string $subject, string $name): string
    {
        $trimmed = trim($name);

        return $trimmed === '' ? throw InvalidValue::empty($subject) : $trimmed;
    }

    /**
     * @param list<string> $names
     *
     * @return non-empty-list<string>
     */
    private static function names(string $subject, array $names): array
    {
        $clean = array_map(static fn(string $name): string => self::name($subject, $name), $names);

        return $clean === [] ? throw InvalidValue::empty($subject) : $clean;
    }
}
