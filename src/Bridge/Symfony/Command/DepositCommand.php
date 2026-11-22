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
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Files;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;

/**
 * Deposits one DPAE flow (XML) read from a file. The mode is required and never implied (ADR 0003); a real deposit is
 * refused by a test installation before anything is recorded or sent.
 */
#[AsCommand('urssaf:dpae:deposit', 'Deposits one DPAE flow (XML file) with URSSAF, as is')]
final class DepositCommand extends Command
{
    private const ARGUMENT_FILE = 'file';

    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly DepositDpaeHandler $deposit,
        private readonly TestIndicators $indicators,
        private readonly OperationLocks $locks,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(self::ARGUMENT_FILE, InputArgument::REQUIRED, 'XML file of the DPAE flow (sent unchanged)');
        CommandOptions::addMode($this);
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Your reference of the declaration, unique per tenant (no personal data): a second run with the same flow is not deposited twice');
        CommandOptions::addDryRun($this, 'Check the flow and its mode locally, without recording or calling URSSAF');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $mode = CommandOptions::mode($input);
            $tenant = CommandOptions::singleTenant($input, $this->configuredTenants);
            $reference = CommandOptions::text($input, 'reference') ?? throw new InvalidOptionException('The --reference option is required.');
            $flow = DpaeFlow::fromXml(self::read($input), $mode, $this->indicators);
            if (CommandOptions::dryRun($input)) {
                $output->writeln(\sprintf('Dry run: the flow is valid for the "%s" mode (%d bytes, fingerprint %s); nothing was recorded nor sent.', $mode->value, \strlen($flow->bytes()), $flow->fingerprint()->value()));

                return Command::SUCCESS;
            }
            $command = new DepositDpae($tenant, ExternalReference::fromString($reference), $flow);

            return $this->locks->exclusively(
                'deposit',
                [$tenant->value(), $command->externalReference->value()],
                fn(): int => CommandReports::renderDeposit($output, ($this->deposit)($command)),
                static fn(string $resource): int => CommandReports::renderBusy($output, $resource),
            );
        });
    }

    /**
     * @throws InvalidArgumentException when the file cannot be read
     */
    private static function read(InputInterface $input): string
    {
        return Files::read(CommandOptions::argument($input, self::ARGUMENT_FILE)) ?? throw new InvalidArgumentException('The flow file does not exist or cannot be read.');
    }
}
