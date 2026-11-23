<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Command;

use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Downloads the document of a tracked return and keeps it (ADR 0006, 0008). A document already stored is returned
 * without a call; a return `downloaded` whose document was purged from the store is unavailable, without a call. A 404 marks the return `purged`, a return with no usable document `not_downloadable`; both are
 * rethrown after the state was saved. Downloads are read-only: transport errors and 5xx stay retryable.
 */
final readonly class DownloadReturnHandler
{
    public function __construct(
        private ReturnRepository $returns,
        private ReturnContentStore $contents,
        private DpaeGateway $gateway,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * @throws RecordNotFound          `return.not_found` when the return was never listed
     * @throws ReturnNoLongerAvailable the return is purged, or its stored document was
     * @throws ReturnNotDownloadable   the return has no document, or its `url` is outside the download origin
     */
    public function __invoke(DownloadReturn $command): ReturnContent
    {
        $tracked = $this->returns->find($command->tenantId, $command->identity)
            ?? throw RecordNotFound::withIdentifier('return', $command->identity->flowId()->value());
        $stored = $this->contents->find($command->tenantId, $command->identity);
        if ($stored instanceof ReturnContent) {
            return $stored;
        }
        if ($tracked->downloadState() === DownloadState::Downloaded) {
            // Downloaded once, the document was purged since (retention of the host): nothing to ask URSSAF, and the
            // state `downloaded` is not left.
            throw ReturnNoLongerAvailable::forReturn($tracked->reference());
        }

        $content = $this->fetch($tracked);
        $this->update($tracked, function (TrackedReturn $tracked, Instant $now) use ($command, $content): void {
            $this->contents->save($command->tenantId, $command->identity, $content);
            $tracked->markDownloaded($now);
        });

        return $content;
    }

    /**
     * @throws ReturnNoLongerAvailable the return is purged: it is saved as such first
     * @throws ReturnNotDownloadable   the return has no usable document: it is saved as such first
     */
    private function fetch(TrackedReturn $tracked): ReturnContent
    {
        try {
            return $this->gateway->downloadReturn($tracked->tenantId(), $tracked->reference());
        } catch (ReturnNoLongerAvailable $unavailable) {
            $this->update($tracked, static function (TrackedReturn $tracked, Instant $now): void {
                $tracked->markPurged($now);
            });

            throw $unavailable;
        } catch (ReturnNotDownloadable $notDownloadable) {
            $this->update($tracked, static function (TrackedReturn $tracked, Instant $now): void {
                $tracked->markNotDownloadable($now);
            });

            throw $notDownloadable;
        }
    }

    /**
     * @param-immediately-invoked-callable $change
     *
     * @param callable(TrackedReturn, Instant): void $change
     */
    private function update(TrackedReturn $tracked, callable $change): void
    {
        $this->unitOfWork->run(function (RecordedEvents $events) use ($tracked, $change): void {
            $change($tracked, Instant::fromDateTime($this->clock->now()));
            $events->record(...$tracked->releaseEvents());
            $this->returns->save($tracked);
        });
    }
}
