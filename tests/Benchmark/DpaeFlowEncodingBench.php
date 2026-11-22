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

use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\DpaeFlowBuilder;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire\ExampleBuilder;

/**
 * Encoding of a deposit: the declaration written as the ISO-8859-1 flow by the builder, the flow checked again as a raw
 * flow (well-formedness, mode indicator, fingerprint), and the gzip body the HTTP client sends (a deposit is one flow
 * per call, so one flow is the largest unit a call accepts).
 */
final readonly class DpaeFlowEncodingBench
{
    private DpaeFlowBuilder $builder;

    private Declaration $declaration;

    private DpaeFlow $flow;

    public function __construct()
    {
        $this->builder = ExampleBuilder::defaults();
        $this->declaration = ExampleDeclaration::declaration();
        $this->flow = $this->builder->build($this->declaration, DeclarationMode::Test);
    }

    public function benchBuildAFlow(): void
    {
        $this->builder->build($this->declaration, DeclarationMode::Test);
    }

    public function benchCheckARawFlow(): void
    {
        DpaeFlow::fromXml($this->flow->bytes(), DeclarationMode::Test, TestIndicators::defaults());
    }

    public function benchBuildAndCompressAFlow(): void
    {
        gzencode($this->builder->build($this->declaration, DeclarationMode::Test)->bytes());
    }
}
