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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * Execution guard and output of the console commands.
 */
final class CommandReports
{
    /**
     * Runs a command body, turning package failures and invalid options into an error message and a failure status.
     *
     * @param callable(): int $action
     * @param-immediately-invoked-callable $action
     */
    public static function guard(OutputInterface $output, callable $action): int
    {
        try {
            return $action();
        } catch (ValidationFailed $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));
            foreach ($failure->result()->errors() as $violation) {
                $output->writeln(self::violationLine($violation));
            }

            return Command::FAILURE;
        } catch (UrssafException|ExceptionInterface $failure) {
            $output->writeln(\sprintf('<error>%s</error>', $failure->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * Tells that the same operation is already running (its lock is held): nothing is done, which is not a failure.
     */
    public static function renderBusy(OutputInterface $output, string $resource): int
    {
        $output->writeln(\sprintf('<comment>Already running (lock "%s" is held): skipped.</comment>', $resource));

        return Command::SUCCESS;
    }

    /**
     * Prints what a dry run would process.
     *
     * @param list<TenantId> $tenants
     */
    public static function renderPlan(OutputInterface $output, array $tenants): int
    {
        $table = new Table($output);
        $table->setHeaders(['Tenant']);
        foreach ($tenants as $tenant) {
            $table->addRow([$tenant->value()]);
        }
        $table->render();
        $output->writeln(\sprintf('Dry run: %d tenant(s) would be processed, URSSAF was not called.', \count($tenants)));

        return Command::SUCCESS;
    }

    /**
     * Prints the deposit as stored after the attempt: a failure status unless URSSAF issued a receipt.
     */
    public static function renderDeposit(OutputInterface $output, Deposit $deposit): int
    {
        $table = new Table($output);
        $table->setHeaders(['Deposit', 'Reference', 'Mode', 'State', 'Flux', 'Attempts', 'Last error']);
        $table->addRow([
            $deposit->id()->value(),
            $deposit->externalReference()->value(),
            $deposit->mode()->value,
            $deposit->state()->value,
            $deposit->flowId()?->value() ?? '',
            $deposit->attempts(),
            $deposit->lastErrorCode() ?? '',
        ]);
        $table->render();

        return $deposit->state() === DepositState::Received ? Command::SUCCESS : Command::FAILURE;
    }

    private static function violationLine(Violation $violation): string
    {
        return \sprintf('  - %s: %s', $violation->path(), $violation->code());
    }
}
