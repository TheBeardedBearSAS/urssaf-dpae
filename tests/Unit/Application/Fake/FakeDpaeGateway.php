<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use Closure;
use LogicException;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use Throwable;

/**
 * A scripted gateway: each call consumes the next scripted answer (a value, or a throwable to throw) and is recorded.
 */
final class FakeDpaeGateway implements DpaeGateway
{
    /**
     * @var list<DepositResult|Throwable>
     */
    private array $deposits = [];

    /**
     * @var list<list<ReturnReference>|Throwable>
     */
    private array $listings = [];

    /**
     * @var list<ReturnContent|Throwable>
     */
    private array $downloads = [];

    /**
     * @var list<TenantId>
     */
    private array $depositedFor = [];

    /**
     * @var list<DpaeFlow>
     */
    private array $depositedFlows = [];

    /**
     * @var list<array{TenantId, Instant, ?Instant}>
     */
    private array $listCalls = [];

    /**
     * @var list<ReturnReference>
     */
    private array $downloadCalls = [];

    /**
     * @var Closure(): void
     */
    private Closure $onDeposit;

    public function __construct()
    {
        $this->onDeposit = static function (): void {};
    }

    /**
     * @return list<TenantId>
     */
    public function depositedFor(): array
    {
        return $this->depositedFor;
    }

    /**
     * @return list<DpaeFlow>
     */
    public function depositedFlows(): array
    {
        return $this->depositedFlows;
    }

    /**
     * @return list<array{TenantId, Instant, ?Instant}>
     */
    public function listCalls(): array
    {
        return $this->listCalls;
    }

    /**
     * @return list<ReturnReference>
     */
    public function downloadCalls(): array
    {
        return $this->downloadCalls;
    }

    public function willDeposit(DepositResult|Throwable $answer): void
    {
        $this->deposits[] = $answer;
    }

    /**
     * @param list<ReturnReference>|Throwable $answer
     */
    public function willList(array|Throwable $answer): void
    {
        $this->listings[] = $answer;
    }

    public function willDownload(ReturnContent|Throwable $answer): void
    {
        $this->downloads[] = $answer;
    }

    /**
     * Runs when a deposit call starts, before its answer: lets a test inspect the world at send time.
     *
     * @param Closure(): void $hook
     */
    public function onDeposit(Closure $hook): void
    {
        $this->onDeposit = $hook;
    }

    public function deposit(TenantId $tenantId, DpaeFlow $flow): DepositResult
    {
        $this->depositedFor[] = $tenantId;
        $this->depositedFlows[] = $flow;
        ($this->onDeposit)();
        $answer = array_shift($this->deposits) ?? throw new LogicException('No scripted deposit answer.');

        return $answer instanceof Throwable ? throw $answer : $answer;
    }

    public function listReturns(TenantId $tenantId, Instant $from, ?Instant $to): array
    {
        $this->listCalls[] = [$tenantId, $from, $to];
        $answer = array_shift($this->listings) ?? throw new LogicException('No scripted listing.');

        return $answer instanceof Throwable ? throw $answer : $answer;
    }

    public function downloadReturn(TenantId $tenantId, ReturnReference $return): ReturnContent
    {
        $this->downloadCalls[] = $return;
        $answer = array_shift($this->downloads) ?? throw new LogicException('No scripted download.');

        return $answer instanceof Throwable ? throw $answer : $answer;
    }
}
