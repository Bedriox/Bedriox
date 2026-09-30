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

namespace Bedriox\Server\Entity\Ai;

final class VanillaAiMemories
{
    private static ?AiMemoryType $wanderUntil = null;
    private static ?AiMemoryType $wanderMotionX = null;
    private static ?AiMemoryType $wanderMotionZ = null;
    private static ?AiMemoryType $nearestPlayer = null;
    private static ?AiMemoryType $lastObservedHealth = null;
    private static ?AiMemoryType $hurt = null;
    private static ?AiMemoryType $meleeCooldown = null;
    private static ?AiMemoryType $meleeIntent = null;
    private static ?AiMemoryType $rangedCooldown = null;
    private static ?AiMemoryType $rangedIntent = null;
    private static ?AiMemoryType $temptingPlayer = null;

    public static function wanderUntil(): AiMemoryType
    {
        return self::$wanderUntil ??= new AiMemoryType(0, 'bedriox:wander_until');
    }

    public static function wanderMotionX(): AiMemoryType
    {
        return self::$wanderMotionX ??= new AiMemoryType(1, 'bedriox:wander_motion_x');
    }

    public static function wanderMotionZ(): AiMemoryType
    {
        return self::$wanderMotionZ ??= new AiMemoryType(2, 'bedriox:wander_motion_z');
    }

    public static function nearestPlayer(): AiMemoryType
    {
        return self::$nearestPlayer ??= new AiMemoryType(3, 'bedriox:nearest_player');
    }

    public static function lastObservedHealth(): AiMemoryType
    {
        return self::$lastObservedHealth ??= new AiMemoryType(4, 'bedriox:last_observed_health');
    }

    public static function hurt(): AiMemoryType
    {
        return self::$hurt ??= new AiMemoryType(5, 'bedriox:hurt');
    }

    public static function meleeCooldown(): AiMemoryType
    {
        return self::$meleeCooldown ??= new AiMemoryType(6, 'bedriox:melee_cooldown');
    }

    public static function meleeIntent(): AiMemoryType
    {
        return self::$meleeIntent ??= new AiMemoryType(7, 'bedriox:melee_intent');
    }

    public static function rangedCooldown(): AiMemoryType
    {
        return self::$rangedCooldown ??= new AiMemoryType(8, 'bedriox:ranged_cooldown');
    }

    public static function rangedIntent(): AiMemoryType
    {
        return self::$rangedIntent ??= new AiMemoryType(9, 'bedriox:ranged_intent');
    }

    public static function temptingPlayer(): AiMemoryType
    {
        return self::$temptingPlayer ??= new AiMemoryType(10, 'bedriox:tempting_player');
    }
}
