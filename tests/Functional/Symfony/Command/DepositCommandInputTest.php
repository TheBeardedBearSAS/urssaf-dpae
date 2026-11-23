<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;

final class DepositCommandInputTest extends TestCase
{
    private Workspace $files;

    protected function setUp(): void
    {
        $this->files = new Workspace();
    }

    public function testTheOnlyConfiguredTenantIsTheDefaultOne(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        static::assertSame(0, $harness->command('urssaf:dpae:deposit')->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']));
        static::assertSame(0, $harness->command('urssaf:dpae:deposit')->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1', '--tenant' => 'tenant-1']));
    }

    public function testSeveralTenantsNeedTheOptionAndAnUnknownTenantFails(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['tenants' => ['tenant-1', 'tenant-2']]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $missing = $harness->command('urssaf:dpae:deposit');
        static::assertSame(1, $missing->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']));
        static::assertStringContainsString('The --tenant option is required unless exactly one tenant is configured.', $missing->getDisplay());

        $unknown = $harness->command('urssaf:dpae:deposit');
        static::assertSame(1, $unknown->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1', '--tenant' => 'nobody']));
        static::assertStringContainsString('nobody', $unknown->getDisplay());
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('refusedInputs')]
    public function testItRefusesIncompleteOrInvalidInput(array $input, string $message): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1', ...$input]);

        static::assertSame(1, $status);
        static::assertStringContainsString($message, $command->getDisplay());
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'unknown mode' => [['--mode' => 'production'], 'The --mode option must be "test" or "real".'];
        yield 'file that is not there' => [['file' => 'there-is-no-such-file.xml'], 'The flow file does not exist or cannot be read.'];
        yield 'directory instead of a file' => [['file' => __DIR__], 'flow.empty'];
    }

    public function testTheModeAndTheReferenceAreRequired(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Test, 'a'));

        $noMode = $harness->command('urssaf:dpae:deposit');
        static::assertSame(1, $noMode->execute(['file' => $file, '--reference' => 'ref-1']));
        static::assertStringContainsString('The --mode option is required: "test" or "real".', $noMode->getDisplay());

        $noReference = $harness->command('urssaf:dpae:deposit');
        static::assertSame(1, $noReference->execute(['file' => $file, '--mode' => 'test']));
        static::assertStringContainsString('The --reference option is required.', $noReference->getDisplay());
    }

    public function testARealDepositIsRefusedByATestInstallationBeforeAnythingIsSent(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Real, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'real', '--reference' => 'ref-1']);

        static::assertSame(1, $status);
        static::assertStringContainsString('The flow is deposited in a mode the installation does not allow.', $command->getDisplay());
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }

    public function testAFlowThatDoesNotMatchTheModeIsRefusedWithItsViolations(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $file = $this->files->write('flow.xml', Flows::xml(DeclarationMode::Real, 'a'));

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1']);

        static::assertSame(1, $status);
        static::assertStringContainsString('Validation failed with 1 error(s).', $command->getDisplay());
        static::assertStringContainsString('flow.declaration_mode_mismatch', $command->getDisplay());
    }

    public function testADryRunChecksTheFlowWithoutRecordingNorSending(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flow = Flows::xml(DeclarationMode::Test, 'a');
        $file = $this->files->write('flow.xml', $flow);

        $command = $harness->command('urssaf:dpae:deposit');
        $status = $command->execute(['file' => $file, '--mode' => 'test', '--reference' => 'ref-1', '--dry-run' => true]);

        static::assertSame(0, $status);
        static::assertStringContainsString(\sprintf('Dry run: the flow is valid for the "test" mode (%d bytes, fingerprint %s)', \strlen($flow), hash('sha256', $flow)), $command->getDisplay());
        static::assertSame([], $harness->get(SimulatedDpaeGateway::class)->calls());
    }
}
