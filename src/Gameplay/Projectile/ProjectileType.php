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

namespace Bedriox\Server\Gameplay\Projectile;

/** Stable gameplay projectile identity; values are canonical actor identifiers, not packet IDs. */
enum ProjectileType: string
{
    case SPLASH_POTION = 'minecraft:splash_potion';
    case LINGERING_POTION = 'minecraft:lingering_potion';
    case ARROW = 'minecraft:arrow';
    case TRIDENT = 'minecraft:thrown_trident';
    case FISHING_HOOK = 'minecraft:fishing_hook';
    case ENDER_PEARL = 'minecraft:ender_pearl';
    case SMALL_FIREBALL = 'minecraft:small_fireball';
    case FIREBALL = 'minecraft:fireball';
    case DRAGON_FIREBALL = 'minecraft:dragon_fireball';
    case SHULKER_BULLET = 'minecraft:shulker_bullet';

    public function gravity(): float
    {
        return match ($this) {
            self::TRIDENT => 0.1,
            self::FISHING_HOOK => 0.03,
            self::SMALL_FIREBALL, self::FIREBALL, self::DRAGON_FIREBALL, self::SHULKER_BULLET => 0.0,
            default => 0.05,
        };
    }

    public function drag(): float
    {
        return match ($this) {
            self::FISHING_HOOK => 0.08,
            self::SMALL_FIREBALL, self::FIREBALL, self::DRAGON_FIREBALL, self::SHULKER_BULLET => 0.0,
            default => 0.01,
        };
    }

    public function isPotion(): bool
    {
        return $this === self::SPLASH_POTION || $this === self::LINGERING_POTION;
    }
}
