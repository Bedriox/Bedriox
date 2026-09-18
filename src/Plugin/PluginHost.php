<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\Event\OwnedEventRegistrar;
use Closure;
use Throwable;

final class PluginHost
{
    private readonly PluginOwnershipRegistry $ownership;
    private readonly PluginActionBuffer $actions;
    private readonly PluginExecutionContext $execution;
    private readonly PluginManager $manager;
    private readonly EventDispatcher $events;
    /** @var list<PluginPackage> */
    private array $packages = [];
    private bool $started = false;

    /**
     * @param Closure(PluginManifest, string, OwnedEventRegistrar, ServerPluginLogger): PluginContext $contextFactory
     */
    public function __construct(
        private readonly string $pluginsDirectory,
        private readonly string $pluginDataDirectory,
        private readonly ServerLogger $logger,
        private readonly Closure $contextFactory,
        private readonly int $maximumPlugins = 64,
        ?CrashContextPublisher $crashContext = null,
    ) {
        $this->ownership = new PluginOwnershipRegistry();
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
                $pluginLogger = new ServerPluginLogger($package->manifest->name, $this->logger);
                $context = ($this->contextFactory)($package->manifest, $dataFolder, $registrar, $pluginLogger);
                $plugin = ($package->instantiate)($context);
                $this->manager->add($package->manifest, $plugin);
            } catch (Throwable $failure) {
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
        $this->manager->loadAll();
        $this->manager->enableAll();
        foreach ($this->packages as $package) {
            if ($this->manager->isEnabled($package->manifest->name)) {
                $this->logger->info("Enabled {$package->manifest->name} {$package->manifest->version}", 'Plugins');
            }
        }
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->manager->disableAll();
        foreach (array_reverse($this->packages) as $package) {
            spl_autoload_unregister($package->autoloader);
        }
        $this->packages = [];
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

    /** @internal Used by the simulation-backed public API composition. */
    public function actions(): PluginActionBuffer
    {
        return $this->actions;
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
}
