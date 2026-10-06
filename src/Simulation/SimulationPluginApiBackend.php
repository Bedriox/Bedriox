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

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Encounter\EncounterManager;
use Bedriox\Api\Server;
use Bedriox\Api\Whitelist\Whitelist;
use Bedriox\Api\World\WorldManager as ApiWorldManager;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\WorldRuntimeManager;
use Bedriox\Server\Simulation\Encounter\SimulationEncounterManager;
use Bedriox\Server\World\Block\BlockStateRegistry;

/** @internal Owns the only public-API projection into the authoritative simulation. */
final readonly class SimulationPluginApiBackend
{
    public function __construct(
        private WorldSimulation $simulation,
        private ?ItemCatalog $itemCatalog = null,
        private ?BlockStateRegistry $blockStateRegistry = null,
        private ?WorldRuntimeManager $worldRuntimes = null,
        private ?ApiWorldManager $worldManager = null,
        private ?Whitelist $whitelist = null,
    ) {}

    /** @internal Plugin item definitions enter the simulation through this bounded registry. */
    public function itemBehaviorRegistry(): ItemBehaviorRegistry
    {
        return $this->simulation->itemBehaviorRegistry();
    }

    /** @internal Used to translate admitted plugin recipe outputs without exposing process-local IDs. */
    public function blockStateRegistry(): BlockStateRegistry
    {
        return $this->blockStateRegistry
            ?? throw new \LogicException('The internal block-state registry is unavailable.');
    }

    public function serverFor(
        string $plugin,
        PluginRuntimeControl $plugins,
        PluginActionBuffer $actions,
    ): Server {
        return new SimulationPluginServer(
            $plugin,
            $plugins,
            $actions,
            $this->pluginPlayers(...),
            $this->pluginPlayer(...),
            worldManager: $this->worldManager,
            whitelist: $this->whitelist,
        );
    }

    public function containerManagerFor(
        string $plugin,
        PluginRuntimeControl $plugins,
        PluginActionBuffer $actions,
        PluginOwnershipRegistry $ownership,
    ): \Bedriox\Api\Inventory\ContainerManager {
        return (new SimulationPluginContainerService(
            $plugin,
            $plugins,
            $actions,
            $ownership,
            $this->simulation,
            $this->itemCatalog,
            $this->worldRuntimes,
        ))->manager();
    }

    public function encounterManagerFor(
        string $plugin,
        PluginRuntimeControl $plugins,
    ): EncounterManager {
        return new SimulationEncounterManager(
            $plugin,
            $plugins,
            $this->simulations(...),
        );
    }

    /** @return list<\Bedriox\Api\Player\Player> */
    private function pluginPlayers(): array
    {
        $players = [];
        foreach ($this->simulations() as $simulation) {
            array_push($players, ...$simulation->pluginPlayers());
        }

        return $players;
    }

    private function pluginPlayer(string $identity): ?\Bedriox\Api\Player\Player
    {
        foreach ($this->simulations() as $simulation) {
            $player = $simulation->pluginPlayer($identity);
            if ($player !== null) {
                return $player;
            }
        }

        return null;
    }

    /** @return list<WorldSimulation> */
    private function simulations(): array
    {
        if ($this->worldRuntimes === null) {
            return [$this->simulation];
        }

        return array_map(
            static fn(\Bedriox\Server\Runtime\ManagedWorldRuntime $runtime): WorldSimulation => $runtime->simulation,
            $this->worldRuntimes->loadedDimensions(),
        );
    }
}
