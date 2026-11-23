<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Benchmark;

use TheBeardedBear\UrssafDpae\Domain\Declaration\Contract;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationRules;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employee;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employer;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleContract;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployee;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployer;

/**
 * Local validation of a declaration (employer, employee, contract, section 3.4 rules) before a flow is built: the
 * official example, without throwing and with the exception on success.
 */
final readonly class DeclarationValidationBench
{
    private Employer $employer;

    private Employee $employee;

    private Contract $contract;

    private DeclarationRules $rules;

    public function __construct()
    {
        $this->employer = ExampleEmployer::with([]);
        $this->employee = ExampleEmployee::with([]);
        $this->contract = ExampleContract::with([]);
        $this->rules = ExampleDeclaration::rules();
    }

    public function benchValidateADeclaration(): void
    {
        Declaration::validate($this->employer, $this->employee, $this->contract, $this->rules, ExampleDeclaration::today());
    }

    public function benchBuildAValidDeclaration(): void
    {
        Declaration::of($this->employer, $this->employee, $this->contract, $this->rules, ExampleDeclaration::today());
    }
}
