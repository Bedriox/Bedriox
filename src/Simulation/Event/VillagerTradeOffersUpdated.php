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

use InvalidArgumentException;

/** One authoritative villager-offer snapshot for an already opened trade window. */
final readonly class VillagerTradeOffersUpdated implements WorldEvent
{
    public function __construct(
        public string $ownerSessionId,
        public int $windowId,
        public int $tradeTier,
        public int $villagerUniqueId,
        public int $playerUniqueId,
        public string $displayName,
        public string $offersLittleEndianNbt,
    ) {
        if ($ownerSessionId === '' || $windowId < 2 || $windowId > 99
            || $tradeTier < 0 || $tradeTier > 4 || $villagerUniqueId < 1 || $playerUniqueId < 1
            || $displayName === '' || strlen($displayName) > 256 || preg_match('//u', $displayName) !== 1
            || $offersLittleEndianNbt === '' || strlen($offersLittleEndianNbt) > 262_144) {
            throw new InvalidArgumentException('Villager trade-offer projection is invalid.');
        }
    }

    public function recipients(): array
    {
        return [$this->ownerSessionId];
    }
}
