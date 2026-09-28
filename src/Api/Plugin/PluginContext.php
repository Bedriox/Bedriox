<?php

declare(strict_types=1);

namespace Bedriox\Api\Plugin;

use Bedriox\Api\Command\CommandRegistrar;
use Bedriox\Api\Crafting\RecipeRegistrar;
use Bedriox\Api\Crafting\UnavailableRecipeRegistrar;
use Bedriox\Api\Entity\EntityRegistrar;
use Bedriox\Api\Entity\UnavailableEntityRegistrar;
use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Inventory\ContainerManager;
use Bedriox\Api\Inventory\ItemRegistrar;
use Bedriox\Api\Inventory\UnavailableItemRegistrar;
use Bedriox\Api\Scheduler\PluginScheduler;
use Bedriox\Api\Scheduler\UnavailablePluginScheduler;
use Bedriox\Api\Server;
use Bedriox\Api\World\Generator\GeneratorRegistrar;
use Bedriox\Api\World\Generator\UnavailableGeneratorRegistrar;

final class PluginContext
{
    public function __construct(
        private readonly string $name,
        private readonly PluginLogger $logger,
        private readonly EventRegistrar $events,
        private readonly CommandRegistrar $commands,
        private readonly SourcePluginRegistrar $sourcePlugins,
        private readonly Server $server,
        private readonly string $dataFolder,
        private readonly ItemRegistrar $items = new UnavailableItemRegistrar(),
        private readonly PluginScheduler $scheduler = new UnavailablePluginScheduler(),
        private readonly RecipeRegistrar $recipes = new UnavailableRecipeRegistrar(),
        private readonly EntityRegistrar $entities = new UnavailableEntityRegistrar(),
        private readonly GeneratorRegistrar $generators = new UnavailableGeneratorRegistrar(),
        private readonly ?ContainerManager $containers = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function logger(): PluginLogger
    {
        return $this->logger;
    }

    public function events(): EventRegistrar
    {
        return $this->events;
    }

    public function commands(): CommandRegistrar
    {
        return $this->commands;
    }

    public function sourcePlugins(): SourcePluginRegistrar
    {
        return $this->sourcePlugins;
    }

    public function server(): Server
    {
        return $this->server;
    }

    public function dataFolder(): string
    {
        return $this->dataFolder;
    }

    public function items(): ItemRegistrar
    {
        return $this->items;
    }

    public function scheduler(): PluginScheduler
    {
        return $this->scheduler;
    }

    public function recipes(): RecipeRegistrar
    {
        return $this->recipes;
    }

    public function entities(): EntityRegistrar
    {
        return $this->entities;
    }

    public function generators(): GeneratorRegistrar
    {
        return $this->generators;
    }

    public function containers(): ContainerManager
    {
        return $this->containers
            ?? throw new \LogicException('The container capability is unavailable.');
    }

    /** @internal Used by the server composition root to attach an owner-scoped scheduler. */
    public function withScheduler(PluginScheduler $scheduler): self
    {
        return new self(
            $this->name,
            $this->logger,
            $this->events,
            $this->commands,
            $this->sourcePlugins,
            $this->server,
            $this->dataFolder,
            $this->items,
            $scheduler,
            $this->recipes,
            $this->entities,
            $this->generators,
            $this->containers,
        );
    }

    /** @internal Used by the server composition root to attach an owner-scoped generator registrar. */
    public function withGenerators(GeneratorRegistrar $generators): self
    {
        return new self(
            $this->name,
            $this->logger,
            $this->events,
            $this->commands,
            $this->sourcePlugins,
            $this->server,
            $this->dataFolder,
            $this->items,
            $this->scheduler,
            $this->recipes,
            $this->entities,
            $generators,
            $this->containers,
        );
    }
}
