<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Command;

use DateInterval;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafDpae\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafDpae\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;

#[AsCommand('urssaf:dpae:purge-outbox', 'Deletes the outbox entries dispatched for longer than the retention')]
final class PurgeOutboxCommand extends Command
{
    public function __construct(
        private readonly PurgeOutboxHandler $purge,
        private readonly OperationLocks $locks,
        private readonly DateInterval $retention,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, fn(): int => $this->locks->exclusively(
            'purge-outbox',
            ['*'],
            function () use ($output): int {
                $output->writeln(\sprintf('%d dispatched outbox entries deleted.', ($this->purge)(new PurgeOutbox($this->retention))));

                return Command::SUCCESS;
            },
            static fn(string $resource): int => CommandReports::renderBusy($output, $resource),
        ));
    }
}
