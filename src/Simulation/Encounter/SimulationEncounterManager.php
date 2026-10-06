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

namespace Bedriox\Server\Simulation\Encounter;

use Bedriox\Api\Encounter\EncounterManager;
use Bedriox\Api\Encounter\EnderDragonEncounter;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\WorldSimulation;
use Closure;

/** @internal Plugin-scoped encounter access over authoritative simulations. */
final readonly class SimulationEncounterManager implements EncounterManager
{
    /** @param Closure(): list<WorldSimulation> $simulations */
    public function __construct(
        private string $plugin,
        private PluginRuntimeControl $plugins,
        private Closure $simulations,
    ) {}

    public function getEnderDragonEncounter(World $world): ?EnderDragonEncounter
    {
        $this->assertEnabled();
        foreach (($this->simulations)() as $simulation) {
            if ($simulation->worldId() !== $world->id() || $simulation->dimension() !== WorldDimension::END) {
                continue;
            }
            $encounter = $simulation->endEncounterView();
            if ($encounter === null) {
                return null;
            }

            return $encounter;
        }

        return null;
    }

    private function assertEnabled(): void
    {
        if (!$this->plugins->isEnabled($this->plugin)) {
            throw new PluginException("Disabled plugin {$this->plugin} cannot use the encounter API.");
        }
    }
}
