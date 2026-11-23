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

use DateTimeImmutable;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * The contract of a DPAE, limited to the elements of the official example (ADR 0007).
 *
 * Plain data, validated by {@see self::validate()}. The hire hour and minute rule of code `35` holds by construction
 * (`DateTimeImmutable`); the end of contract has a date only (URSSAF initialises the time, §3.4).
 */
final readonly class Contract
{
    use ForbidsSerialization;

    private function __construct(
        private DateTimeImmutable $start,
        private ?DateTimeImmutable $endDate,
        private ContractType $type,
        private ?string $healthService,
    ) {}

    public static function of(
        DateTimeImmutable $start,
        ?DateTimeImmutable $endDate,
        ContractType $type,
        ?string $healthService,
    ): self {
        return new self($start, $endDate, $type, $healthService);
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function endDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function type(): ContractType
    {
        return $this->type;
    }

    public function healthService(): ?string
    {
        return $this->healthService;
    }

    /**
     * Paths are relative to the contract; `$today` is the reference of "within one year before and after" (same calendar day,
     * 29 February clamped to 28 February).
     */
    public function validate(DateTimeImmutable $today): ValidationResult
    {
        $violations = [];
        $startDay = $this->start->format('Y-m-d');
        if ($startDay < self::sameDayOtherYear($today, -1)) {
            $violations[] = Violation::error(
                'start',
                'contract.start_too_early',
                'The hire date must not be more than one year in the past.',
                ['normCode' => '35'],
            );
        }
        if ($startDay > self::sameDayOtherYear($today, 1)) {
            $violations[] = Violation::error(
                'start',
                'contract.start_too_late',
                'The hire date must not be more than one year in the future.',
                ['normCode' => '35'],
            );
        }
        if ($this->endDate instanceof DateTimeImmutable && $this->endDate->format('Y-m-d') < $startDay) {
            $violations[] = Violation::error(
                'endDate',
                'contract.end_before_start',
                'The end date must not be before the hire date.',
                ['normCode' => '46'],
            );
        }
        if ($this->type !== ContractType::Ctt && ($this->healthService === null || trim($this->healthService) === '')) {
            $violations[] = Violation::error(
                'healthService',
                'contract.health_service_empty',
                'The health service is mandatory except for a CTT.',
                ['normCode' => '50'],
            );
        }

        return ValidationResult::of(...$violations);
    }

    /**
     * The same calendar day one year before or after, as `Y-m-d`. A 29 February overflows to 1 March of a common year,
     * which is brought back to 28 February (so the window is not shortened by a day).
     *
     * @param -1|1 $years
     */
    private static function sameDayOtherYear(DateTimeImmutable $day, int $years): string
    {
        $shifted = $day->modify(\sprintf('%+d year', $years));
        if ($shifted->format('j') !== $day->format('j')) {
            $shifted = $shifted->modify('last day of previous month');
        }

        return $shifted->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type];
    }
}
