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

namespace Bedriox\Api\Encounter;

use Bedriox\Api\BossBar\BossBar;
use Bedriox\Api\Entity\Vanilla\EndCrystal;
use Bedriox\Api\Entity\Vanilla\EnderDragon;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;

interface EnderDragonEncounter
{
    public function getDragon(): ?EnderDragon;

    public function getBossBar(): BossBar;

    public function getPhase(): EnderDragonPhase;

    /** @return list<Player> */
    public function getParticipants(): array;

    /** @return list<EndCrystal> */
    public function getHealingCrystals(): array;

    public function hasBeenCompleted(): bool;

    public function getExitPortalPosition(): ?BlockPosition;

    public function getGatewayCount(): int;

    public function getRespawnStage(): EnderDragonRespawnStage;

    public function getController(): EnderDragonEncounterController;
}
