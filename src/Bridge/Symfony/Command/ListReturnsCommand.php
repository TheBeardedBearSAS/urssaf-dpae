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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturns;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Lists (and stores) the returns published in a range. Read-only for URSSAF; it never moves the synchronisation cursor.
 */
#[AsCommand('urssaf:dpae:returns', 'Lists the returns published from a date, or in a range, without moving the synchronisation cursor')]
final class ListReturnsCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ListReturnsHandler $list,
        private readonly Clock $clock,
        private readonly WorkflowPolicy $policy,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the range, ISO 8601 with an offset (default: now minus the initial lookback of the workflow policy)');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the range, ISO 8601 with an offset (default: no end, the one-date path)');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandOptions::singleTenant($input, $this->configuredTenants);
            $from = CommandOptions::instant($input, 'from') ?? Instant::fromDateTime($this->clock->now()->sub($this->policy->initialLookback()));
            $returns = ($this->list)(new ListReturns($tenant, $from, CommandOptions::instant($input, 'to')));
            $table = new Table($output);
            $table->setHeaders(['Flux', 'Nature code', 'Status code', 'Ops', 'Publication', 'Document']);
            $table->setRows(array_map(static fn(ReturnReference $return): array => [
                $return->flowId()->value(),
                $return->natureCode(),
                $return->statusCode(),
                $return->ops() ?? '-',
                $return->publicationRaw(),
                $return->hasDocument() ? 'yes' : 'no',
            ], $returns));
            $table->render();
            $output->writeln(\sprintf('%d return(s).', \count($returns)));

            return Command::SUCCESS;
        });
    }
}
