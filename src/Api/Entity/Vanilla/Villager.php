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

namespace Bedriox\Api\Entity\Vanilla;

use Bedriox\Api\Entity\Capability\Ageable;
use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Value\VillagerActivity;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\World\BlockPosition;

interface Villager extends Ageable
{
    public function getProfession(): VillagerProfession;

    public function getVillagerType(): VillagerType;

    public function getVillagerLevel(): VillagerLevel;

    public function getVillagerExperience(): int;

    public function getTrades(): VillagerTradeList;

    public function getActivity(): VillagerActivity;

    public function isSleeping(): bool;

    public function getBedPosition(): ?BlockPosition;

    public function getWorkstationPosition(): ?BlockPosition;
}
