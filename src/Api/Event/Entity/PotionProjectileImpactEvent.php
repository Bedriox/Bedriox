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

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Potion\PotionType;
use Bedriox\Api\World\Position;

/** Fired before a thrown potion or tipped arrow applies its authoritative impact. */
final class PotionProjectileImpactEvent extends CancellableEvent
{
    public function __construct(
        public readonly int $runtimeEntityId,
        public readonly string $ownerUuid,
        public readonly PotionType $potionType,
        public readonly Position $position,
        public readonly bool $lingering,
        public readonly bool $tippedArrow,
    ) {}
}
