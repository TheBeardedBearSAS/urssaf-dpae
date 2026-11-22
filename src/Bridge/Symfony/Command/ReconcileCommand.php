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
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Dto\ReconciliationReport;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\RetryDelays;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Settles the deposits whose outcome is unknown by searching the returns of URSSAF: it never resends a flow. A deposit
 * that needs a human (several candidates, out of the retention) makes the command fail.
 */
#[AsCommand('urssaf:dpae:reconcile', 'Reconciles the deposits of unknown outcome with the returns of URSSAF (never resends)')]
final class ReconcileCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly ReconcileUnknownDepositsHandler $reconcile,
        private readonly OperationLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        CommandOptions::addTenant($this, 'Tenant id (default: the configured tenants)');
        CommandOptions::addDryRun($this, 'List the tenants that would be reconciled, without calling URSSAF');
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
            $table->setHeaders(['Tenant', 'Marked unknown', 'Adopted', 'Needing review', 'Unattached flux', 'Outcome']);
            $failed = false;
            foreach ($tenants as $tenant) {
                $row = $this->locks->exclusively(
                    'reconcile',
                    [$tenant->value()],
                    fn(): array => $this->reconcileTenant($tenant),
                    static fn(string $resource): array => [$tenant->value(), '', '', '', '', \sprintf('skipped (lock "%s" is held)', $resource), false],
                );
                $failed = $failed || $row[6];
                $table->addRow(\array_slice($row, 0, 6));
            }
            $table->render();

            return $failed ? Command::FAILURE : Command::SUCCESS;
        });
    }

    /**
     * @return array{string, int|string, int|string, int|string, int|string, string, bool} the table cells, and whether the tenant needs attention
     */
    private function reconcileTenant(TenantId $tenant): array
    {
        try {
            $report = ($this->reconcile)(new ReconcileUnknownDeposits($tenant));
        } catch (UrssafException $failure) {
            return [$tenant->value(), '', '', '', '', $failure->getMessage(), true];
        }

        return [
            $tenant->value(),
            \count($report->markedUnknown),
            \count($report->adopted),
            \count($report->needingReview),
            \count($report->unattachedFlux),
            self::outcome($report),
            $report->needingReview !== [],
        ];
    }

    private static function outcome(ReconciliationReport $report): string
    {
        return $report->refused ? \sprintf('refused, retry in %ds', RetryDelays::seconds($report->retryAfterSeconds)) : 'reconciled';
    }
}
