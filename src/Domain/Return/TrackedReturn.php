<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * A return of a tenant followed by the package: its latest listed form, the local deposit it belongs to (same
 * `flux.id`, ADR 0006) and the state of its download. Identified by `(tenant, flux.id, nature, ops)`.
 *
 * Not `readonly`: it is upserted by every synchronisation.
 */
final class TrackedReturn
{
    private const AGGREGATE = 'tracked_return';

    private DownloadState $downloadState = DownloadState::Pending;

    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    private function __construct(
        private readonly TenantId $tenantId,
        private ReturnReference $reference,
        private ?DepositId $depositId,
        private readonly Instant $firstSeenAt,
        private Instant $updatedAt,
    ) {}

    /**
     * A return seen for the first time; records {@see ReturnAvailable}.
     */
    public static function discover(TenantId $tenantId, ReturnReference $reference, ?DepositId $depositId, Instant $now): self
    {
        $tracked = new self($tenantId, $reference, $depositId, $now, $now);
        $tracked->events[] = new ReturnAvailable($tenantId, $reference, $depositId, $now);

        return $tracked;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function reference(): ReturnReference
    {
        return $this->reference;
    }

    public function identity(): ReturnIdentity
    {
        return $this->reference->identity();
    }

    public function depositId(): ?DepositId
    {
        return $this->depositId;
    }

    public function downloadState(): DownloadState
    {
        return $this->downloadState;
    }

    public function firstSeenAt(): Instant
    {
        return $this->firstSeenAt;
    }

    public function updatedAt(): Instant
    {
        return $this->updatedAt;
    }

    /**
     * Takes the latest listed form (status, dates, `id`, `url` of a known return may change). Records
     * {@see ReturnAvailable} when the return gains a document.
     *
     * @throws InvariantViolation `return.identity_mismatch` when the listed return is another one
     */
    public function refresh(ReturnReference $latest, Instant $now): void
    {
        if (!$latest->identity()->equals($this->reference->identity())) {
            throw InvariantViolation::because('return.identity_mismatch', 'The listed return is not the tracked one.');
        }
        if (!$this->reference->hasDocument() && $latest->hasDocument()) {
            $this->events[] = new ReturnAvailable($this->tenantId, $latest, $this->depositId, $now);
            if ($this->downloadState === DownloadState::NotDownloadable) {
                $this->downloadState = DownloadState::Pending;
            }
        }
        $this->reference = $latest;
        $this->updatedAt = $now;
    }

    /**
     * Links the return to the local deposit with the same `flux.id`. An existing link is kept.
     */
    public function linkTo(DepositId $depositId): void
    {
        $this->depositId ??= $depositId;
    }

    /**
     * A successful download proves the return is downloadable, whatever was thought before.
     *
     * @throws InvalidStateTransition
     */
    public function markDownloaded(Instant $now): void
    {
        $this->moveTo(DownloadState::Downloaded, $now, DownloadState::Pending, DownloadState::NotDownloadable);
        $this->events[] = new ReturnDownloaded($this->tenantId, $this->reference, $this->depositId, $now);
    }

    /**
     * The return has no usable document. Marking it again changes nothing; gaining a document brings it back to
     * `pending` ({@see self::refresh()}).
     *
     * @throws InvalidStateTransition
     */
    public function markNotDownloadable(Instant $now): void
    {
        if ($this->downloadState === DownloadState::NotDownloadable) {
            return;
        }
        $this->moveTo(DownloadState::NotDownloadable, $now, DownloadState::Pending);
    }

    /**
     * URSSAF answered 404: the return was purged (kept three months, ADR 0006). Asking again changes nothing: the
     * state is final, and a return that was not downloadable may be purged too.
     *
     * @throws InvalidStateTransition
     */
    public function markPurged(Instant $now): void
    {
        if ($this->downloadState === DownloadState::Purged) {
            return;
        }
        $this->moveTo(DownloadState::Purged, $now, DownloadState::Pending, DownloadState::NotDownloadable);
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function moveTo(DownloadState $to, Instant $now, DownloadState ...$from): void
    {
        if (!\in_array($this->downloadState, $from, true)) {
            throw InvalidStateTransition::between(self::AGGREGATE, $this->downloadState->value, $to->value);
        }
        $this->downloadState = $to;
        $this->updatedAt = $now;
    }
}
