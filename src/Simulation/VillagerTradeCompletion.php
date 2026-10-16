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

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;

/** @internal Fully validated trade mutation retained across the inventory commit boundary. */
final readonly class VillagerTradeCompletion
{
    public function __construct(
        public VillagerEntity $villager,
        public VillagerTradeList $trades,
        public VillagerTradeOffer $offer,
        public int $offerIndex,
        public int $tradeCount,
    ) {}
}
