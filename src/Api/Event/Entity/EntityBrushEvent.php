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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Vanilla\Armadillo;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;

/** Cancellable intent emitted before an armadillo is brushed. */
final class EntityBrushEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Armadillo $entity,
        public readonly ItemStack $tool,
        public readonly ItemStack $drop,
    ) {}
}
