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

use Bedriox\Api\BossBar\BossBar;
use Bedriox\Api\Encounter\EnderDragonEncounter;
use Bedriox\Api\Encounter\EnderDragonEncounterController;
use Bedriox\Api\Encounter\EnderDragonPhase;
use Bedriox\Api\Encounter\EnderDragonRespawnStage;
use Bedriox\Api\Entity\Vanilla\EndCrystal;
use Bedriox\Api\Entity\Vanilla\EnderDragon;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Gameplay\End\EndEncounterCoordinator;
use Bedriox\Server\Simulation\WorldSimulation;

/** @internal Live bounded public projection of an End encounter. */
final readonly class SimulationEnderDragonEncounter implements EnderDragonEncounter
{
    public function __construct(
        private WorldSimulation $simulation,
        private EndEncounterCoordinator $encounter,
        private BossBar $bossBar,
        private EnderDragonEncounterController $controller,
    ) {}

    public function getDragon(): ?EnderDragon
    {
        $uuid = $this->encounter->state()->dragonUuid;
        $entity = $uuid === null ? null : $this->simulation->entityRuntime()->registry()->getByUniqueId($uuid);

        return $entity instanceof EnderDragonEntity ? $entity : null;
    }

    public function getBossBar(): BossBar
    {
        return $this->bossBar;
    }

    public function getPhase(): EnderDragonPhase
    {
        return EnderDragonPhase::from($this->encounter->state()->phase->value);
    }

    public function getParticipants(): array
    {
        return $this->simulation->pluginEndEncounterParticipants();
    }

    public function getHealingCrystals(): array
    {
        return array_values(array_filter(
            $this->simulation->entityRuntime()->registry()->all(),
            static fn($entity): bool => $entity instanceof EndCrystalEntity && $entity->isAlive(),
        ));
    }

    public function hasBeenCompleted(): bool
    {
        return $this->encounter->state()->previouslyKilledDragon;
    }

    public function getExitPortalPosition(): ?BlockPosition
    {
        return $this->encounter->state()->exitPortalActive
            ? new BlockPosition(0, 69, 0, WorldDimension::END)
            : null;
    }

    public function getGatewayCount(): int
    {
        return $this->encounter->state()->gatewayCount;
    }

    public function getRespawnStage(): EnderDragonRespawnStage
    {
        return $this->encounter->state()->respawnStage->value === 'none'
            ? EnderDragonRespawnStage::IDLE
            : EnderDragonRespawnStage::from($this->encounter->state()->respawnStage->value);
    }

    public function getController(): EnderDragonEncounterController
    {
        return $this->controller;
    }
}
