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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Semantic attack intent applied by WorldSimulation through normal damage/projectile paths. */
final readonly class EnderDragonAttack
{
    public function __construct(
        public EnderDragonAttackType $type,
        public Position $origin,
        public Position $target,
        public string $targetUuid,
        public float $damage,
        public float $radius,
        public float $knockback,
    ) {
        if ($targetUuid === '' || strlen($targetUuid) > 64 || preg_match('//u', $targetUuid) !== 1
            || !is_finite($damage) || $damage < 0.0 || $damage > 64.0
            || !is_finite($radius) || $radius <= 0.0 || $radius > 32.0
            || !is_finite($knockback) || $knockback < 0.0 || $knockback > 8.0) {
            throw new InvalidArgumentException('Ender Dragon attack is outside its supported bounds.');
        }
    }
}
