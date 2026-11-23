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

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafDpae\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafDpae\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;

#[AsCommand('urssaf:dpae:relay-outbox', 'Delivers the pending integration events of the outbox')]
final class RelayOutboxCommand extends Command
{
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly RelayOutboxHandler $relay,
        private readonly OperationLocks $locks,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addLimit($this, \sprintf('Maximum number of events delivered by this run (default: %d)', self::DEFAULT_LIMIT));
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $limit = CommandOptions::limit($input, self::DEFAULT_LIMIT);

            return $this->locks->exclusively(
                'relay-outbox',
                ['*'],
                function () use ($output, $limit): int {
                    $report = ($this->relay)(new RelayOutbox($limit));
                    $table = new Table($output);
                    $table->setHeaders(['Dispatched', 'Failed', 'Deferred']);
                    $table->setRows([[$report->dispatched, $report->failed, $report->deferred]]);
                    $table->render();

                    return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
                },
                static fn(string $resource): int => CommandReports::renderBusy($output, $resource),
            );
        });
    }
}
