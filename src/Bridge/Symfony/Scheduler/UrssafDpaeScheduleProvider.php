<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Scheduler;

use Override;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The crons of the package as a Symfony Scheduler schedule: each task runs the console commands through Messenger
 * ({@see RunCommandMessage}), exactly like the crontab of the documentation (same locks, same safety nets).
 */
final readonly class UrssafDpaeScheduleProvider implements ScheduleProviderInterface
{
    /** The schedule name: consume it with "messenger:consume scheduler_urssaf_dpae" (ADR 0009). */
    public const NAME = 'urssaf_dpae';

    /** Console commands of each task of the "scheduler.frequencies" option. */
    public const TASKS = [
        'sync_returns' => ['urssaf:dpae:sync-returns'],
        'reconcile' => ['urssaf:dpae:reconcile'],
        'relay_outbox' => ['urssaf:dpae:relay-outbox'],
        'purge_outbox' => ['urssaf:dpae:purge-outbox'],
    ];

    /**
     * @param array<string, ?string> $frequencies ISO 8601 duration of each task, null to leave it out
     */
    public function __construct(private array $frequencies) {}

    #[Override]
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        foreach (self::TASKS as $task => $commands) {
            $frequency = $this->frequencies[$task] ?? null;
            if ($frequency === null) {
                continue;
            }
            foreach ($commands as $command) {
                $schedule->add(RecurringMessage::every($frequency, new RunCommandMessage($command)));
            }
        }

        return $schedule;
    }
}
