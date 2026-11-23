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
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Dto\SynchronizationReport;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\RetryDelays;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Polls the returns of the tenants from their cursor. A refusal of the polling constraints (422 / 429) is reported, the
 * cursor staying where it is; a tenant that needs attention makes the command fail.
 */
#[AsCommand('urssaf:dpae:sync-returns', 'Synchronises the returns of the tenants from their cursor', ['urssaf:dpae:sync'])]
final class SyncReturnsCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly SynchronizeReturnsHandler $synchronize,
        private readonly OperationLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addTenant($this, 'Tenant id (default: the configured tenants)');
        CommandOptions::addDryRun($this, 'List the tenants that would be synchronised, without calling URSSAF');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenants = CommandOptions::tenantsOrConfigured($input, $this->configuredTenants);
            if (CommandOptions::dryRun($input)) {
                return CommandReports::renderPlan($output, $tenants);
            }
            $table = new Table($output);
            $table->setHeaders(['Tenant', 'Discovered', 'Windows', 'Outcome']);
            $failed = false;
            foreach ($tenants as $tenant) {
                $row = $this->locks->exclusively(
                    'sync-returns',
                    [$tenant->value()],
                    fn(): array => $this->synchronizeTenant($tenant),
                    static fn(string $resource): array => [$tenant->value(), '', '', \sprintf('skipped (lock "%s" is held)', $resource), false],
                );
                $failed = $failed || $row[4];
                $table->addRow(\array_slice($row, 0, 4));
            }
            $table->render();

            return $failed ? Command::FAILURE : Command::SUCCESS;
        });
    }

    /**
     * @return array{string, int|string, int|string, string, bool} the table cells, and whether the tenant failed
     */
    private function synchronizeTenant(TenantId $tenant): array
    {
        try {
            $report = ($this->synchronize)(new SynchronizeReturns($tenant));
        } catch (UrssafException $failure) {
            return [$tenant->value(), '', '', $failure->getMessage(), true];
        }

        return [$tenant->value(), $report->discovered, $report->windows, self::outcome($report), $report->needsAttention];
    }

    private static function outcome(SynchronizationReport $report): string
    {
        if (!$report->refused) {
            return 'synchronised';
        }

        return \sprintf('refused, retry in %ds%s', RetryDelays::seconds($report->retryAfterSeconds), $report->needsAttention ? ' (needs attention)' : '');
    }
}
