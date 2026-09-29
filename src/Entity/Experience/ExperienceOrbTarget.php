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

namespace Bedriox\Server\Entity\Experience;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Bounded player projection used by orb attraction and pickup decisions. */
final readonly class ExperienceOrbTarget
{
    public function __construct(
        public string $sessionId,
        public int $runtimeActorId,
        public Position $pickupPosition,
        public bool $alive = true,
        public bool $spectator = false,
        public bool $canAttract = true,
        public bool $canPickup = true,
    ) {
        if ($sessionId === '' || strlen($sessionId) > 128 || $runtimeActorId < 1) {
            throw new InvalidArgumentException('Experience-orb target identity is invalid.');
        }
        if (!is_finite($pickupPosition->x) || !is_finite($pickupPosition->y) || !is_finite($pickupPosition->z)) {
            throw new InvalidArgumentException('Experience-orb target position must be finite.');
        }
    }

    public function eligibleForAttraction(): bool
    {
        return $this->alive && !$this->spectator && $this->canAttract;
    }
}
