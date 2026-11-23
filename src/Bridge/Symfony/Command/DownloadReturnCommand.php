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
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturn;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Files;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Integers;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;

/**
 * Downloads the document of a return already listed (urssaf:dpae:returns or urssaf:dpae:sync-returns) and writes it to a
 * file: the documents carry personal data, they are never printed.
 */
#[AsCommand('urssaf:dpae:download', 'Downloads the document of a listed return (AEE, certificate, report) into a file')]
final class DownloadReturnCommand extends Command
{
    /**
     * @param list<string> $configuredTenants
     */
    public function __construct(
        private readonly DownloadReturnHandler $download,
        private readonly array $configuredTenants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('flowId', InputArgument::REQUIRED, 'Flux id ("flux.id" of the return)');
        $this->addArgument('nature', InputArgument::REQUIRED, 'Nature code of the return: 10, 11, 20 or 41');
        CommandOptions::addTenant($this, 'Tenant id (default: the only configured tenant)');
        $this->addOption('ops', null, InputOption::VALUE_REQUIRED, 'The "ops" of the return, when it has one (never for a DPAE return of the SI DPAE)');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'File the document is written to (required), created readable by its owner only (0600)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the --output file when it already exists');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CommandReports::guard($output, function () use ($input, $output): int {
            $tenant = CommandOptions::singleTenant($input, $this->configuredTenants);
            $target = CommandOptions::text($input, 'output') ?? throw new InvalidOptionException('The --output option is required.');
            $overwrite = $input->getOption('force') === true;
            if (!$overwrite && file_exists($target)) {
                throw new InvalidOptionException('The --output file already exists: choose another file or add --force to overwrite it.');
            }
            $identity = new ReturnIdentity(FlowId::fromString(CommandOptions::argument($input, 'flowId')), self::natureCode($input), CommandOptions::text($input, 'ops'));
            $content = ($this->download)(new DownloadReturn($tenant, $identity));
            if (!($overwrite ? Files::write(...) : Files::create(...))($target, $content->bytes())) {
                throw new InvalidOptionException('The --output file cannot be written.');
            }
            $output->writeln(\sprintf('Document written (%d bytes, %s).', $content->size(), $content->contentType()));

            return Command::SUCCESS;
        });
    }

    private static function natureCode(InputInterface $input): int
    {
        $code = Integers::parse(CommandOptions::argument($input, 'nature'));

        return $code ?? throw new InvalidArgumentException('The nature must be an integer code: 10, 11, 20 or 41.');
    }
}
