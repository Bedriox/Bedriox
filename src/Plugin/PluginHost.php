<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Plugin\Data\PluginData;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Plugin\SourcePluginDefinition;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\BossBar\BossBarRegistry;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Command\OwnedCommandRegistrar;
use Bedriox\Server\Plugin\Data\ArrayPluginResourceProvider;
use Bedriox\Server\Plugin\Data\FilePluginData;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\Event\OwnedEventRegistrar;
use Bedriox\Server\Plugin\Scheduler\MainThreadPluginScheduler;
use Bedriox\Server\Plugin\Scheduler\PluginAsyncTaskExecutor;
use Closure;
use Throwable;

final class PluginHost
{
    private readonly PluginOwnershipRegistry $ownership;
    private readonly PluginActionBuffer $actions;
    private readonly PluginExecutionContext $execution;
    private readonly PluginManager $manager;
    private readonly EventDispatcher $events;
    private readonly CommandRegistry $commands;
    private readonly MainThreadPluginScheduler $scheduler;
    private readonly PluginEntityRegistrar $entities;
    private readonly BossBarRegistry $bossBars;
    /** @var list<PluginPackage> */
    private array $packages = [];
    /** @var array<string, array{provider: string, definitions: list<SourcePluginDefinition>}> */
    private array $sourceBatches = [];
    private bool $acceptingSourcePlugins = false;
    private bool $started = false;

    /**
     * @param Closure(PluginManifest, PluginData, OwnedEventRegistrar, OwnedCommandRegistrar, OwnedSourcePluginRegistrar, ServerPluginLogger): PluginContext $contextFactory
     */
    public function __construct(
        private readonly string $pluginsDirectory,
        private readonly string $pluginDataDirectory,
        private readonly ServerLogger $logger,
        private readonly Closure $contextFactory,
        private readonly int $maximumPlugins = 64,
        ?CrashContextPublisher $crashContext = null,
        ?PluginAsyncTaskExecutor $asyncTaskExecutor = null,
        ?Closure $onlinePlayers = null,
        ?Closure $commandEntities = null,
        ?Closure $commandSelectorOrigin = null,
    ) {
        $this->ownership = new PluginOwnershipRegistry();
        $onlinePlayers ??= static fn(): array => [];
        $this->bossBars = new BossBarRegistry($this->ownership, $onlinePlayers);
        $this->actions = new PluginActionBuffer();
        $this->execution = new PluginExecutionContext(crashContext: $crashContext);
        $this->manager = new PluginManager($this->execution, $this->ownership, function (PluginFailure $failure): void {
            $this->logger->error(sprintf(
                'Plugin %s failed during %s (%s) and was disabled',
                $failure->plugin,
                $failure->operation,
                $failure->throwable::class,
            ), 'Plugins');
        });
        $this->events = new EventDispatcher($this->manager, $this->execution, $this->actions, $this->ownership);
        $this->commands = new CommandRegistry(
            $this->manager,
            $this->execution,
            $this->actions,
            $this->ownership,
            $this->events,
            onlinePlayers: $onlinePlayers,
            entities: $commandEntities,
            selectorOrigin: $commandSelectorOrigin,
            failureReporter: function (
                Throwable $failure,
                string $operation,
                ?string $commandName,
                ?string $commandOwner,
            ): void {
                $identity = $commandName === null
                    ? 'unknown command'
                    : ($commandOwner ?? 'unknown owner') . ':' . $commandName;
                $this->logger->error(sprintf(
                    'Command %s failed during %s (%s)',
                    $identity,
                    $operation,
                    $failure::class,
                ), 'Command');
            },
        );
        $this->scheduler = new MainThreadPluginScheduler(
            $this->manager,
            $this->execution,
            $this->actions,
            $this->ownership,
            $asyncTaskExecutor,
        );
        $this->entities = new PluginEntityRegistrar(
            $this->manager,
            $this->execution,
            $this->actions,
            $this->ownership,
        );
    }

    public function start(): void
    {
        if ($this->started) {
            throw new PluginException('Plugin host has already started.');
        }
        $this->started = true;
        $loader = new PluginPackageLoader($this->maximumPlugins);
        $packages = $loader->discover(
            $this->pluginsDirectory,
            function (string $directory, Throwable $failure): void {
                $this->logger->error(sprintf(
                    'Plugin directory %s was rejected (%s)',
                    $directory,
                    $failure::class,
                ), 'Plugins');
            },
        );
        $plan = (new PluginDependencyPlanner())->plan($packages);
        foreach ($plan->rejectedByName as $name => $reason) {
            $this->logger->error("Plugin {$name} was rejected: {$reason}", 'Plugins');
        }
        $accepted = [];
        $pluginsRoot = realpath($this->pluginsDirectory);
        if ($pluginsRoot === false) {
            throw new PluginException('Unable to resolve the plugins directory.');
        }
        foreach ($plan->ordered as $package) {
            $dependenciesReady = true;
            foreach ($package->manifest->dependencies as $dependency) {
                if (!isset($accepted[strtolower($dependency)])) {
                    $dependenciesReady = false;
                    $this->logger->error("Plugin {$package->manifest->name} was rejected because dependency {$dependency} did not load", 'Plugins');
                    break;
                }
            }
            if (!$dependenciesReady) {
                continue;
            }
            try {
                $dataFolder = $this->dataFolder($package->manifest->name);
                $registrar = new OwnedEventRegistrar($package->manifest->name, $this->events);
                $commands = new OwnedCommandRegistrar($package->manifest->name, $this->commands);
                $sourcePlugins = new OwnedSourcePluginRegistrar(
                    $package->manifest->name,
                    $pluginsRoot,
                    $this->stageSourcePlugins(...),
                );
                $pluginLogger = new ServerPluginLogger($package->manifest->name, $this->logger);
                $context = ($this->contextFactory)(
                    $package->manifest,
                    new FilePluginData($dataFolder, $package->resources),
                    $registrar,
                    $commands,
                    $sourcePlugins,
                    $pluginLogger,
                );
                $ownedScheduler = $this->scheduler->forPlugin(
                    $package->manifest->name,
                    $package->manifest->version,
                    $package->archiveIdentity,
                );
                $context = $context->withScheduler($ownedScheduler);
                $ownedScheduler->attachContext($context);
                $plugin = ($package->instantiate)($context);
                $this->manager->add($package->manifest, $plugin);
            } catch (Throwable $failure) {
                $this->ownership->releaseAll($package->manifest->name);
                $this->logger->error(sprintf(
                    'Plugin %s could not be prepared (%s)',
                    $package->manifest->name,
                    $failure::class,
                ), 'Plugins');
                continue;
            }
            $accepted[strtolower($package->manifest->name)] = true;
            $this->packages[] = $package;
        }
        foreach ($packages as $package) {
            if (!isset($accepted[strtolower($package->manifest->name)])) {
                spl_autoload_unregister($package->autoloader);
            }
        }
        $this->logger->info(sprintf('Discovered %d plugin%s', count($this->packages), count($this->packages) === 1 ? '' : 's'), 'Plugins');
        $this->acceptingSourcePlugins = true;
        try {
            $this->manager->loadAll();
            $this->manager->enableAll();
        } finally {
            $this->acceptingSourcePlugins = false;
        }
        foreach ($this->packages as $package) {
            if ($this->manager->isEnabled($package->manifest->name)) {
                $this->logger->info("Enabled {$package->manifest->name} {$package->manifest->version}", 'Plugins');
            }
        }
        $this->admitSourcePlugins($pluginsRoot);
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->manager->disableAll();
        $this->scheduler->shutdown();
        foreach (array_reverse($this->packages) as $package) {
            spl_autoload_unregister($package->autoloader);
        }
        $this->packages = [];
        $this->sourceBatches = [];
        $this->started = false;
    }

    public function events(): EventDispatcher
    {
        return $this->events;
    }

    public function manager(): PluginManager
    {
        return $this->manager;
    }

    public function commands(): CommandRegistry
    {
        return $this->commands;
    }

    /** @internal Invoked once at the authoritative server-tick boundary. */
    public function tickScheduler(int $currentTick): void
    {
        $this->scheduler->tick($currentTick);
    }

    public function scheduler(): MainThreadPluginScheduler
    {
        return $this->scheduler;
    }

    /** @internal Used to construct owner-scoped public entity registrars. */
    public function entities(): PluginEntityRegistrar
    {
        return $this->entities;
    }

    /** @internal Used to construct owner-scoped public boss-bar managers. */
    public function bossBars(string $plugin): \Bedriox\Api\BossBar\BossBarManager
    {
        return $this->bossBars->forOwner($plugin);
    }

    /** @internal Used by the simulation-backed public API composition. */
    public function actions(): PluginActionBuffer
    {
        return $this->actions;
    }

    /** @internal Used to bind plugin-owned public resources to lifecycle cleanup. */
    public function ownership(): PluginOwnershipRegistry
    {
        return $this->ownership;
    }

    private function dataFolder(string $plugin): string
    {
        if (!is_dir($this->pluginDataDirectory)
            && !@mkdir($this->pluginDataDirectory, 0o775, true)
            && !is_dir($this->pluginDataDirectory)) {
            throw new PluginException('Unable to create the plugin data directory.');
        }
        $root = realpath($this->pluginDataDirectory);
        if ($root === false) {
            throw new PluginException('Unable to resolve the plugin data directory.');
        }
        $folder = $root . DIRECTORY_SEPARATOR . $plugin;
        if (!is_dir($folder) && !@mkdir($folder, 0o775) && !is_dir($folder)) {
            throw new PluginException("Unable to create data directory for plugin {$plugin}.");
        }
        $resolved = realpath($folder);
        if ($resolved === false || !str_starts_with(
            strtolower(str_replace('\\', '/', $resolved)) . '/',
            strtolower(rtrim(str_replace('\\', '/', $root), '/') . '/'),
        )) {
            throw new PluginException("Plugin data directory for {$plugin} escapes its root.");
        }

        return $resolved;
    }

    /** @param list<SourcePluginDefinition> $definitions */
    private function stageSourcePlugins(string $provider, array $definitions): void
    {
        $key = strtolower($provider);
        $frame = $this->execution->current();
        if (!$this->acceptingSourcePlugins || $frame === null || strcasecmp($frame->plugin, $provider) !== 0 || $frame->operation !== 'load') {
            throw new PluginException('Source plugins may be registered only by their provider during onLoad.');
        }
        if (isset($this->sourceBatches[$key])) {
            throw new PluginException("Plugin {$provider} already registered a source batch.");
        }
        if (count($definitions) > $this->maximumPlugins || count($this->packages) + count($definitions) > $this->maximumPlugins) {
            throw new PluginException('Source plugin registrations exceed the configured plugin limit.');
        }
        $this->sourceBatches[$key] = ['provider' => $provider, 'definitions' => $definitions];
        $this->ownership->own($provider, 'source-provider-batch', function () use ($key): void {
            unset($this->sourceBatches[$key]);
        });
    }

    private function admitSourcePlugins(string $pluginsRoot): void
    {
        $packages = [];
        /** @var array<string, array{definition: SourcePluginDefinition, provider: string}> $sources */
        $sources = [];
        $external = [];
        foreach ($this->packages as $package) {
            if ($this->manager->isEnabled($package->manifest->name)) {
                $external[] = $package->manifest->name;
            }
        }
        foreach ($this->sourceBatches as $key => $batch) {
            $this->ownership->forget($batch['provider'], 'source-provider-batch');
            unset($this->sourceBatches[$key]);
            if (!$this->manager->isEnabled($batch['provider'])) {
                foreach ($batch['definitions'] as $definition) {
                    $this->releaseSourceDefinition($definition);
                }
                continue;
            }
            foreach ($batch['definitions'] as $definition) {
                try {
                    if (count($this->packages) + count($packages) >= $this->maximumPlugins) {
                        throw new PluginException('Source plugin registrations exceed the configured plugin limit.');
                    }
                    $manifest = $this->sourceManifest($batch['provider'], $definition);
                    $sourceKey = strtolower($manifest->name);
                    if ($this->manager->has($manifest->name) || isset($sources[$sourceKey])) {
                        throw new PluginException("Duplicate plugin: {$manifest->name}");
                    }
                    $package = new PluginPackage(
                        "source:{$batch['provider']}:{$manifest->name}",
                        $manifest,
                        static function (string $class): void {},
                        $definition->instantiate(...),
                        new ArrayPluginResourceProvider($definition->resources),
                    );
                    $packages[] = $package;
                    $sources[$sourceKey] = ['definition' => $definition, 'provider' => $batch['provider']];
                } catch (Throwable $failure) {
                    $this->logger->error(sprintf(
                        'Source plugin %s from %s was rejected (%s)',
                        $definition->name,
                        $batch['provider'],
                        $failure::class,
                    ), 'Plugins');
                    $this->releaseSourceDefinition($definition);
                }
            }
        }
        if ($packages === []) {
            return;
        }
        $plan = (new PluginDependencyPlanner())->plan($packages, $external);
        foreach ($plan->rejectedByName as $name => $reason) {
            $source = $sources[strtolower($name)] ?? null;
            if ($source !== null) {
                $this->logger->error("Source plugin {$name} was rejected: {$reason}", 'Plugins');
                $this->releaseSourceDefinition($source['definition']);
                unset($sources[strtolower($name)]);
            }
        }
        $prepared = [];
        foreach ($plan->ordered as $package) {
            $source = $sources[strtolower($package->manifest->name)] ?? null;
            if ($source === null) {
                continue;
            }
            try {
                $dataFolder = $this->dataFolder($package->manifest->name);
                $events = new OwnedEventRegistrar($package->manifest->name, $this->events);
                $commands = new OwnedCommandRegistrar($package->manifest->name, $this->commands);
                $sourcePlugins = new OwnedSourcePluginRegistrar(
                    $package->manifest->name,
                    $pluginsRoot,
                    $this->stageSourcePlugins(...),
                );
                $pluginLogger = new ServerPluginLogger($package->manifest->name, $this->logger);
                $context = ($this->contextFactory)(
                    $package->manifest,
                    new FilePluginData($dataFolder, $package->resources),
                    $events,
                    $commands,
                    $sourcePlugins,
                    $pluginLogger,
                );
                $ownedScheduler = $this->scheduler->forPlugin(
                    $package->manifest->name,
                    $package->manifest->version,
                    $package->archiveIdentity,
                );
                $context = $context->withScheduler($ownedScheduler);
                $ownedScheduler->attachContext($context);
                $plugin = ($package->instantiate)($context);
                $this->manager->add($package->manifest, $plugin);
                $this->ownership->own(
                    $package->manifest->name,
                    'source-plugin-loader',
                    $source['definition']->release(...),
                );
                $prepared[] = $package;
            } catch (Throwable $failure) {
                $this->logger->error(sprintf(
                    'Source plugin %s could not be prepared (%s)',
                    $package->manifest->name,
                    $failure::class,
                ), 'Plugins');
                $this->releaseSourceDefinition($source['definition']);
            }
        }
        foreach ($prepared as $package) {
            if ($this->manager->load($package->manifest->name) && $this->manager->enable($package->manifest->name)) {
                $this->logger->info("Enabled source plugin {$package->manifest->name} {$package->manifest->version}", 'Plugins');
            }
        }
    }

    private function sourceManifest(string $provider, SourcePluginDefinition $definition): PluginManifest
    {
        $dependencies = $definition->dependencies;
        if (!$this->containsName($dependencies, $provider)) {
            $dependencies[] = $provider;
        }
        $softDependencies = array_values(array_filter(
            $definition->softDependencies,
            static fn(string $dependency): bool => strcasecmp($dependency, $provider) !== 0,
        ));
        $json = json_encode([
            'schema' => $definition->schema,
            'name' => $definition->name,
            'version' => $definition->version,
            'api' => $definition->api,
            'main' => $definition->main,
            'namespace' => $definition->namespace,
            'authors' => $definition->authors,
            'dependencies' => $dependencies,
            'softDependencies' => $softDependencies,
            'load' => $definition->load,
        ], JSON_THROW_ON_ERROR);
        $manifest = (new PluginManifestParser())->parse($json);
        if (!in_array($manifest->api, [
            '0.4', '0.4.0', '^0.4', '^0.4.0', '~0.4', '~0.4.0',
        ], true)) {
            throw new PluginException("Plugin {$manifest->name} requires unsupported API {$manifest->api}.");
        }

        return $manifest;
    }

    private function releaseSourceDefinition(SourcePluginDefinition $definition): void
    {
        try {
            $definition->release();
        } catch (Throwable) {
            // Rejected source cleanup cannot change admission of unrelated plugins.
        }
    }

    /** @param list<string> $names */
    private function containsName(array $names, string $name): bool
    {
        foreach ($names as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
