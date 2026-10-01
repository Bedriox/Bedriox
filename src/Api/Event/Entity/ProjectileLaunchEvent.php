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
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Cancellable projectile launch with mutable authoritative motion. */
final class ProjectileLaunchEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player|LivingEntity $shooter,
        public readonly int $runtimeEntityId,
        public readonly string $projectileIdentifier,
        public readonly Position $position,
        private Vector3 $motion,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $projectileIdentifier) !== 1) {
            throw new InvalidArgumentException('Projectile identifier must be canonical.');
        }
    }

    public function motion(): Vector3
    {
        return $this->motion;
    }

    public function setMotion(Vector3 $motion): void
    {
        $this->assertMutable();
        $this->motion = $motion;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->motion];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof Vector3) {
            throw new InvalidArgumentException('Invalid projectile launch event state.');
        }
        parent::replaceState($state[0]);
        $this->motion = $state[1];
    }
}
