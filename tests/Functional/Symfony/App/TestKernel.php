<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Http\Mock\Client as MockClient;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\YamlDumper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Transaction\ImmediateTransactionManager;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\CustomCheck;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\ExtensionConfigSnapshot;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\LazyGhostOption;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\NonWaitingStore;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RefusingIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\RecordingLogger;

/**
 * Minimal application hosting the bundle: framework, optionally DoctrineBundle (in-memory SQLite) and an in-memory
 * Messenger transport. The URSSAF HTTP client is a mock ("test.http_client") and the clock a fixed one ("clock").
 *
 * The compiled container is cached per configuration for the duration of the PHP process only (a stale container
 * would hide a code change, and mutation testing swaps classes under a running process).
 */
final class TestKernel extends BaseKernel
{
    use MicroKernelTrait;

    // @mago-expect lint:no-literal-password Fake secret of the test application, never a real credential.
    public const SECRET = 'top-secret-password';
    public const NOW = '2022-03-01 00:00:00 UTC';
    public const SIRET = '12345678901234';

    private static ?string $cacheRoot = null;

    /**
     * @param array<string, mixed>      $bundleConfig configuration of "urssaf_dpae", merged over the defaults
     * @param bool|array<string, mixed> $doctrine     true for DoctrineBundle on an in-memory SQLite, or its own configuration
     * @param bool                      $lock         true to configure the framework lock component (in-memory store)
     */
    public function __construct(
        private readonly array $bundleConfig,
        private readonly bool|array $doctrine,
        private readonly bool $messenger,
        private readonly bool $lock,
    ) {
        $_ENV['URSSAF_DPAE_TEST_PASSWORD'] = self::SECRET;
        parent::__construct('t' . substr(md5(serialize([$bundleConfig, $doctrine, $messenger, $lock])), 0, 12), false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        // @mago-expect lint:no-literal-password The secret is an environment placeholder, set by the constructor.
        return [
            'declaration_mode' => 'test',
            'credentials' => ['tenant-1' => [
                'siret' => self::SIRET,
                'nom' => 'Martin',
                'prenom' => 'Claire',
                'password' => '%env(URSSAF_DPAE_TEST_PASSWORD)%',
                'service' => 25,
            ]],
            'http_client' => 'test.http_client',
            'persistence' => ['driver' => 'in_memory'],
        ];
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new UrssafDpaeBundle();
        if ($this->doctrine !== false) {
            yield new DoctrineBundle();
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 4);
    }

    public function getCacheDir(): string
    {
        if (self::$cacheRoot === null) {
            self::$cacheRoot = $this->getProjectDir() . '/var/t8/cache/' . bin2hex(random_bytes(8));
            register_shutdown_function(static function (): void {
                try {
                    (new Filesystem())->remove((string) self::$cacheRoot);
                } catch (IOException) {
                    // @mago-expect lint:no-empty-catch-clause The cache is disposable: a directory left behind is harmless.
                }
            });
        }

        return self::$cacheRoot . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    /**
     * The compiled container as YAML (what a container debugging command shows).
     */
    public function containerAsYaml(): string
    {
        $this->initializeBundles();
        $container = $this->buildContainer();
        $container->compile();

        return (new YamlDumper($container))->dump();
    }

    /**
     * The services as declared by the bundles: extensions loaded, but nothing removed, inlined nor resolved.
     */
    public function definitions(): ContainerBuilder
    {
        return $this->definitionsRecordedBy(new ExtensionConfigSnapshot(UrssafDpaeBundle::ALIAS));
    }

    public function definitionsRecordedBy(ExtensionConfigSnapshot $snapshot): ContainerBuilder
    {
        $this->initializeBundles();
        $container = $this->buildContainer();
        $container->addCompilerPass($snapshot, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $passes = $container->getCompilerPassConfig();
        $passes->setOptimizationPasses([]);
        $passes->setRemovingPasses([]);
        $passes->setAfterRemovingPasses([]);
        $container->compile();

        return $container;
    }

    public function configureContainer(ContainerConfigurator $container): void
    {
        // @mago-expect lint:no-literal-password The framework "secret" of a throw-away test application.
        $framework = [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'cache' => ['app' => 'cache.adapter.array'],
        ];
        if ($this->messenger) {
            $framework['messenger'] = [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [
                    DepositDpaeMessage::class => 'async',
                    SynchronizeReturnsMessage::class => 'async',
                    ReconcileDepositsMessage::class => 'async',
                ],
            ];
        }
        $container->extension('framework', $framework);
        if ($this->doctrine !== false) {
            $container->extension('doctrine', \is_array($this->doctrine) ? $this->doctrine : [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [...LazyGhostOption::options(), 'enable_native_lazy_objects' => DoctrineIntegration::nativeLazyObjectsAvailable()],
            ]);
        }
        $lock = $this->lock ? ['lock' => ['factory' => 'test.lock_factory']] : [];
        $container->extension(UrssafDpaeBundle::ALIAS, array_replace_recursive(self::defaults(), $lock, $this->bundleConfig));

        $services = $container->services();
        $services->set('test.http_client', MockClient::class)->public();
        $services->set('test.sink', RecordingIntegrationEventSink::class)->args([['refused-aggregate']])->public();
        $services->set('test.deposits', InMemoryDepositRepository::class)->public();
        $services->set('test.returns', InMemoryReturnRepository::class)->public();
        $services->set('test.cursors', InMemorySyncCursorRepository::class)->public();
        $services->set('test.contents', InMemoryReturnContentStore::class)->public();
        $services->set('test.transactions', ImmediateTransactionManager::class)->public();
        $services->set('test.refusing_sink', RefusingIntegrationEventSink::class)->public();
        $services->set('test.outbox', InMemoryOutbox::class)->public();
        $services->set('test.token_store', InMemoryTokenStore::class)->public();
        $services->set('test.credentials', InMemoryCredentialsProvider::class)->args([[]])->public();
        // Public, so that the test can hold a lock "from another process" and the oldest supported Symfony keeps it.
        $services->set('test.lock_store', NonWaitingStore::class)->args([new Definition(InMemoryStore::class)]);
        $services->set('test.lock_factory', LockFactory::class)->args([new Reference('test.lock_store')])->public();
        $services->set(CustomCheck::class)->autoconfigure();
        $services->set('logger', RecordingLogger::class)->public();
        $services->set('clock', MockClock::class)->args([self::NOW])->public();
    }
}
