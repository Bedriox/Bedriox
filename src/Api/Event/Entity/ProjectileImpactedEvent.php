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

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;

/** Observes a projectile impact after authoritative effects have committed. */
final class ProjectileImpactedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly int $runtimeEntityId,
        public readonly Player|LivingEntity|null $shooter,
        public readonly string $projectileIdentifier,
        public readonly Position $position,
        public readonly Player|Entity|null $target,
        public readonly ?BlockPosition $block,
    ) {}
}
