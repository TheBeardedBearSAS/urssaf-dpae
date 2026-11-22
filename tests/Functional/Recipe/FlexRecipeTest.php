<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Recipe;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

/**
 * The Symfony Flex recipe stays in line with the bundle, and the endpoint generator publishes it.
 */
final class FlexRecipeTest extends TestCase
{
    private const RECIPE = __DIR__ . '/../../../recipe/thebeardedbear/urssaf-dpae/0.1';
    private const PACKAGE = 'thebeardedbear/urssaf-dpae';

    public function testTheManifestRegistersTheBundleAndCopiesTheConfiguration(): void
    {
        // @mago-expect analysis:mixed-assignment JSON is untyped: the assertions below narrow it.
        $manifest = json_decode((string) file_get_contents(self::RECIPE . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        static::assertIsArray($manifest);
        static::assertSame([UrssafDpaeBundle::class => ['all']], $manifest['bundles']);
        static::assertSame(['config/' => '%CONFIG_DIR%/'], $manifest['copy-from-recipe']);
        static::assertIsArray($manifest['env']);
        static::assertSame([
            'URSSAF_DPAE_DECLARATION_MODE' => 'test',
            'URSSAF_DPAE_SIRET' => '',
            'URSSAF_DPAE_NOM' => '',
            'URSSAF_DPAE_PRENOM' => '',
            'URSSAF_DPAE_PASSWORD' => '',
            'URSSAF_DPAE_SERVICE' => '25',
            'URSSAF_DPAE_AUTHENTICATION_URI' => '',
            'URSSAF_DPAE_DEPOSIT_URI' => '',
            'URSSAF_DPAE_CONSULTATION_URI' => '',
            'URSSAF_DPAE_DOWNLOAD_URI' => '',
        ], array_diff_key($manifest['env'], ['#1' => true, '#2' => true]));
    }

    public function testEveryVariableIsPrefixed(): void
    {
        $recipe = (string) file_get_contents(self::RECIPE . '/config/packages/urssaf_dpae.yaml');
        $matches = [];
        preg_match_all('/%env\((?:[a-z]+:)*([A-Z0-9_]+)\)%/', $recipe, $matches);

        static::assertNotSame([], $matches[1]);
        foreach ($matches[1] as $name) {
            static::assertStringStartsWith('URSSAF_DPAE_', $name);
        }
    }

    public function testTheCopiedConfigurationBootsTheBundle(): void
    {
        /** @var array{urssaf_dpae: array<string, mixed>, 'when@test': array{urssaf_dpae: array<string, mixed>}} $config */
        $config = self::recipeConfig();
        $main = $config['urssaf_dpae'];
        // @mago-expect lint:no-literal-password Fake credentials of a throw-away test configuration.
        $variables = [
            'URSSAF_DPAE_DECLARATION_MODE' => 'test',
            'URSSAF_DPAE_SIRET' => '12345678901234',
            'URSSAF_DPAE_NOM' => 'Martin',
            'URSSAF_DPAE_PRENOM' => 'Claire',
            'URSSAF_DPAE_PASSWORD' => 'fake-password-1',
            'URSSAF_DPAE_SERVICE' => '98',
            'URSSAF_DPAE_DEPOSIT_URI' => 'https://mock.invalid:9000',
        ];
        foreach ($variables as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
        try {
            // Merged over the test defaults (HTTP client, in-memory persistence, a test tenant).
            $harness = Harness::bootDefault($main);
            $command = $harness->command('urssaf:dpae:check');
            static::assertSame(0, $command->execute(['--skip-remote' => true]));
            $display = $command->getDisplay();
            static::assertMatchesRegularExpression('/^\| credentials +\| default +\| ok /m', $display);
            static::assertMatchesRegularExpression('/^\| declaration mode +\| +\| ok +\| test:/m', $display);
            static::assertStringContainsString('authentication=https://mon.urssaf.fr', $display);
            static::assertStringContainsString('deposit=https://mock.invalid:9000', $display);
            static::assertStringContainsString('consultation=https://consultation.dpae-edi.urssaf.fr:8443', $display);
            static::assertStringContainsString('download=https://telechargement.dpae-edi.urssaf.fr:8443', $display);

            static::assertTrue(Harness::bootDefault([...$main, ...$config['when@test']['urssaf_dpae']])->has(SimulatedDpaeGateway::class));
        } finally {
            foreach (array_keys($variables) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }
    }

    public function testTheTestEnvironmentBootsWithoutAnyVariable(): void
    {
        /** @var array{urssaf_dpae: array<string, mixed>, 'when@test': array{urssaf_dpae: array<string, mixed>}} $config */
        $config = self::recipeConfig();
        foreach (['DECLARATION_MODE', 'SIRET', 'NOM', 'PRENOM', 'PASSWORD', 'SERVICE'] as $name) {
            static::assertArrayNotHasKey('URSSAF_DPAE_' . $name, $_SERVER);
            static::assertArrayNotHasKey('URSSAF_DPAE_' . $name, $_ENV);
        }

        // The recipe as the test environment merges it; the mode is the one variable the recipe would read.
        $harness = Harness::bootDefault([...$config['urssaf_dpae'], ...$config['when@test']['urssaf_dpae'], 'declaration_mode' => 'test']);
        $command = $harness->command('urssaf:dpae:check');

        static::assertSame(0, $command->execute(['--skip-remote' => true]));
        static::assertMatchesRegularExpression('/^\| credentials +\| default +\| ok /m', $command->getDisplay());
    }

    public function testTheEndpointGeneratorPublishesTheRecipe(): void
    {
        $output = \dirname(__DIR__, 3) . '/var/test-flex-endpoint';
        (new Filesystem())->remove($output);
        $process = new Process([PHP_BINARY, \dirname(__DIR__, 3) . '/bin/build-flex-endpoint', $output, 'https://flex.example.com/urssaf-dpae/']);

        static::assertSame(0, $process->run());

        $index = (string) file_get_contents($output . '/index.json');
        static::assertStringContainsString('"aliases": {}', $index);
        static::assertSame([
            'aliases' => [],
            'recipes' => [self::PACKAGE => ['0.1']],
            'branch' => 'main',
            'is_contrib' => false,
            '_links' => [
                'repository' => 'https://flex.example.com/urssaf-dpae',
                'origin_template' => '{package}:{version}@https://flex.example.com/urssaf-dpae',
                'recipe_template' => 'https://flex.example.com/urssaf-dpae/{package_dotted}.{version}.json',
            ],
        ], json_decode($index, true, 512, JSON_THROW_ON_ERROR));
        /** @var array{manifests: array<string, array{manifest: mixed, files: array<string, array{contents: list<string>, executable: bool}>, ref: string}>} $recipe */
        $recipe = json_decode((string) file_get_contents($output . '/thebeardedbear.urssaf-dpae.0.1.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $recipe['manifests'][self::PACKAGE];
        static::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['ref']);
        static::assertSame(['config/packages/urssaf_dpae.yaml'], array_keys($manifest['files']));
        $file = $manifest['files']['config/packages/urssaf_dpae.yaml'];
        static::assertFalse($file['executable']);
        static::assertSame((string) file_get_contents(self::RECIPE . '/config/packages/urssaf_dpae.yaml'), implode("\n", $file['contents']) . "\n");
        static::assertSame(json_decode((string) file_get_contents(self::RECIPE . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR), $manifest['manifest']);
        (new Filesystem())->remove($output);
    }

    public function testTheGeneratorNeedsItsArguments(): void
    {
        $process = new Process([PHP_BINARY, \dirname(__DIR__, 3) . '/bin/build-flex-endpoint']);

        static::assertSame(2, $process->run());
        static::assertStringContainsString('Usage: bin/build-flex-endpoint <output-dir> <base-url>', $process->getErrorOutput());
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function recipeConfig(): array
    {
        // @mago-expect analysis:mixed-assignment YAML is untyped: the assertion below narrows it.
        $config = Yaml::parseFile(self::RECIPE . '/config/packages/urssaf_dpae.yaml');
        static::assertIsArray($config);

        return $config;
    }
}
