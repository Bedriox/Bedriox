<?php

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
}
