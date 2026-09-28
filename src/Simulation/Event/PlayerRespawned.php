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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerRespawned implements WorldEvent
{
    /**
     * @param list<string> $recipientSessionIds
     * @param list<?InventoryStack> $inventory
     * @param list<?InventoryStack> $armor
     */
    public function __construct(
        public PlayerSnapshot $player,
        public array $recipientSessionIds,
        public array $inventory,
        public int $selectedHotbarSlot,
        public ?InventoryStack $selectedStack,
        public array $armor = [],
        public ?InventoryStack $offhand = null,
    ) {}
    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
