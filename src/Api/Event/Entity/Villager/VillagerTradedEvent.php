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

namespace Bedriox\Api\Event\Entity\Villager;

use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Vanilla\Villager;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;

/** Notification emitted after an authoritative villager trade commits. */
final class VillagerTradedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Villager $villager,
        public readonly VillagerTradeOffer $offer,
        public readonly VillagerLevel $villagerLevel,
        public readonly int $villagerExperience,
        public readonly int $playerExperience,
        public readonly int $tradeCount = 1,
    ) {}
}
