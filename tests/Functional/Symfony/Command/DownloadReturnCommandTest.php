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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;

final class DownloadReturnCommandTest extends TestCase
{
    private Workspace $files;

    protected function setUp(): void
    {
        $this->files = new Workspace();
    }

    public function testItWritesTheDocumentToTheOutputFile(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flowId = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a')->flowId();
        static::assertInstanceOf(FlowId::class, $flowId);
        static::assertSame(0, $harness->command('urssaf:dpae:returns')->execute([]));

        $command = $harness->command('urssaf:dpae:download');
        $status = $command->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $this->files->path('aee.xml')]);

        static::assertSame(0, $status);
        static::assertSame(\sprintf('<envoi><IdFluxDPAE>%s</IdFluxDPAE></envoi>', $flowId->value()), $this->files->read('aee.xml'));
        static::assertStringContainsString('Document written (', $command->getDisplay());
        static::assertStringContainsString('application/xml', $command->getDisplay());
        static::assertStringNotContainsString('IdFluxDPAE', $command->getDisplay());
    }

    public function testAReturnThatWasNeverListedIsNotFound(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');

        $command = $harness->command('urssaf:dpae:download');
        $status = $command->execute(['flowId' => 'unknown', 'nature' => '10', '--output' => $this->files->path('aee.xml')]);

        static::assertSame(1, $status);
        static::assertFileDoesNotExist($this->files->path('aee.xml'));
    }

    public function testAPurgedReturnFails(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flowId = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a')->flowId();
        static::assertInstanceOf(FlowId::class, $flowId);
        static::assertSame(0, $harness->command('urssaf:dpae:returns')->execute([]));
        $harness->get(SimulatedDpaeGateway::class)->purge($flowId);

        $command = $harness->command('urssaf:dpae:download');
        $status = $command->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $this->files->path('aee.xml')]);

        static::assertSame(1, $status);
        static::assertFileDoesNotExist($this->files->path('aee.xml'));
    }

    public function testTheOutputFileIsRequiredAndMustBeWritable(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flowId = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a')->flowId();
        static::assertInstanceOf(FlowId::class, $flowId);
        static::assertSame(0, $harness->command('urssaf:dpae:returns')->execute([]));

        $none = $harness->command('urssaf:dpae:download');
        static::assertSame(1, $none->execute(['flowId' => $flowId->value(), 'nature' => '10']));
        static::assertStringContainsString('The --output option is required.', $none->getDisplay());

        foreach ([$this->files->path('missing-directory/aee.xml'), $this->files->path('.')] as $target) {
            $unwritable = $harness->command('urssaf:dpae:download');
            static::assertSame(1, $unwritable->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $target, '--force' => true]));
            static::assertStringContainsString('The --output file cannot be written.', $unwritable->getDisplay());
        }
    }

    public function testAnExistingFileIsNeverReplacedWithoutForce(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flowId = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a')->flowId();
        static::assertInstanceOf(FlowId::class, $flowId);
        static::assertSame(0, $harness->command('urssaf:dpae:returns')->execute([]));
        $target = $this->files->write('aee.xml', 'old');

        $refused = $harness->command('urssaf:dpae:download');
        static::assertSame(1, $refused->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $target]));
        static::assertStringContainsString('already exists', $refused->getDisplay());
        static::assertSame('old', $this->files->read('aee.xml'));

        static::assertSame(0, $harness->command('urssaf:dpae:download')->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $target, '--force' => true]));
        static::assertStringStartsWith('<envoi>', $this->files->read('aee.xml'));
    }

    public function testTheDocumentIsReadableByItsOwnerOnly(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $flowId = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a')->flowId();
        static::assertInstanceOf(FlowId::class, $flowId);
        static::assertSame(0, $harness->command('urssaf:dpae:returns')->execute([]));
        $target = $this->files->write('aee.xml', 'old');
        chmod($target, 0o644);

        static::assertSame(0, $harness->command('urssaf:dpae:download')->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $target, '--force' => true]));
        static::assertSame(0o600, fileperms($target) & 0o777);
        static::assertSame(0, $harness->command('urssaf:dpae:download')->execute(['flowId' => $flowId->value(), 'nature' => '10', '--output' => $this->files->path('new.xml')]));
        static::assertSame(0o600, fileperms($this->files->path('new.xml')) & 0o777);
    }

    public function testTheNatureMustBeAnIntegerCode(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));

        $command = $harness->command('urssaf:dpae:download');
        static::assertSame(1, $command->execute(['flowId' => 'f1', 'nature' => 'AEE', '--output' => $this->files->path('x')]));
        static::assertStringContainsString('The nature must be an integer code: 10, 11, 20 or 41.', $command->getDisplay());
    }
}
