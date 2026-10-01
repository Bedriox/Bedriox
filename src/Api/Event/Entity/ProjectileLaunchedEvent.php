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

use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Entity\Vector3;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;

/** Observes a committed projectile launch. */
final class ProjectileLaunchedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player|LivingEntity $shooter,
        public readonly int $runtimeEntityId,
        public readonly string $projectileIdentifier,
        public readonly Position $position,
        public readonly Vector3 $motion,
    ) {}
}
