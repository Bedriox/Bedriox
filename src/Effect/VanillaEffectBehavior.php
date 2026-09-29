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

namespace Bedriox\Server\Effect;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;

/** Named vanilla mechanics shared by player and entity simulation. @internal */
final readonly class VanillaEffectBehavior
{
    /** @return non-empty-list<EffectBehaviorDomain> */
    public static function domains(EffectType $type): array
    {
        return match ($type) {
            EffectType::SPEED, EffectType::SLOWNESS, EffectType::JUMP_BOOST,
            EffectType::LEVITATION, EffectType::SLOW_FALLING => [EffectBehaviorDomain::MOVEMENT],
            EffectType::HASTE, EffectType::MINING_FATIGUE => [EffectBehaviorDomain::MINING],
            EffectType::STRENGTH, EffectType::INSTANT_DAMAGE, EffectType::RESISTANCE,
            EffectType::WEAKNESS => [EffectBehaviorDomain::COMBAT],
            EffectType::INSTANT_HEALTH, EffectType::REGENERATION, EffectType::POISON,
            EffectType::WITHER, EffectType::FATAL_POISON => [EffectBehaviorDomain::PERIODIC_HEALTH],
            EffectType::HUNGER, EffectType::SATURATION => [EffectBehaviorDomain::NUTRITION],
            EffectType::FIRE_RESISTANCE => [EffectBehaviorDomain::FIRE],
            EffectType::WATER_BREATHING => [EffectBehaviorDomain::BREATHING],
            EffectType::CONDUIT_POWER => [
                EffectBehaviorDomain::BREATHING,
                EffectBehaviorDomain::MINING,
                EffectBehaviorDomain::CLIENT_PRESENTATION,
            ],
            EffectType::HEALTH_BOOST => [EffectBehaviorDomain::HEALTH_CAPACITY],
            EffectType::ABSORPTION => [EffectBehaviorDomain::ABSORPTION],
            EffectType::INVISIBILITY => [EffectBehaviorDomain::VISIBILITY],
            EffectType::NAUSEA, EffectType::BLINDNESS, EffectType::NIGHT_VISION,
            EffectType::DARKNESS => [EffectBehaviorDomain::CLIENT_PRESENTATION],
            EffectType::BAD_OMEN, EffectType::VILLAGE_HERO, EffectType::TRIAL_OMEN,
            EffectType::WIND_CHARGED, EffectType::WEAVING, EffectType::OOZING,
            EffectType::INFESTED => [EffectBehaviorDomain::WORLD_TRIGGER],
        };
    }

    public static function periodicApplications(EffectInstance $effect, int $elapsedTicks): int
    {
        $interval = self::periodicInterval($effect);
        if ($interval === 0 || $effect->infinite) {
            return 0;
        }
        $before = max(0, $effect->durationTicks);
        $after = max(0, $before - $elapsedTicks);

        return intdiv($before, $interval) - intdiv($after, $interval);
    }

    public static function periodicInterval(EffectInstance $effect): int
    {
        return match ($effect->type) {
            EffectType::REGENERATION => max(1, 50 >> min(30, $effect->amplifier)),
            EffectType::POISON, EffectType::FATAL_POISON => max(1, 25 >> min(30, $effect->amplifier)),
            EffectType::WITHER => max(1, 40 >> min(30, $effect->amplifier)),
            EffectType::HUNGER => 1,
            EffectType::SATURATION => 1,
            default => 0,
        };
    }

    /** @param array<string, EffectInstance> $effects */
    public static function movementMultiplier(array $effects): float
    {
        $speed = $effects[EffectType::SPEED->value] ?? null;
        $slowness = $effects[EffectType::SLOWNESS->value] ?? null;
        $multiplier = 1.0;
        if ($speed instanceof EffectInstance) {
            $multiplier *= 1.0 + (0.2 * $speed->level());
        }
        if ($slowness instanceof EffectInstance) {
            $multiplier *= max(0.0, 1.0 - (0.15 * $slowness->level()));
        }

        return $multiplier;
    }

    /** @param array<string, EffectInstance> $effects */
    public static function incomingDamageMultiplier(array $effects): float
    {
        $resistance = $effects[EffectType::RESISTANCE->value] ?? null;

        return $resistance instanceof EffectInstance
            ? max(0.0, 1.0 - (0.2 * $resistance->level()))
            : 1.0;
    }

    /** @param array<string, EffectInstance> $effects */
    public static function attackDamageModifier(array $effects, float $baseDamage): float
    {
        $strength = $effects[EffectType::STRENGTH->value] ?? null;
        $weakness = $effects[EffectType::WEAKNESS->value] ?? null;

        return ($strength instanceof EffectInstance ? $baseDamage * 0.3 * $strength->level() : 0.0)
            - ($weakness instanceof EffectInstance ? $baseDamage * 0.2 * $weakness->level() : 0.0);
    }

    /** @param array<string, EffectInstance> $effects */
    public static function miningHasteLevel(array $effects): int
    {
        $haste = $effects[EffectType::HASTE->value] ?? null;
        $conduit = $effects[EffectType::CONDUIT_POWER->value] ?? null;

        return max(
            $haste instanceof EffectInstance ? $haste->level() : 0,
            $conduit instanceof EffectInstance ? $conduit->level() : 0,
        );
    }

    /** @param array<string, EffectInstance> $effects */
    public static function miningFatigueLevel(array $effects): int
    {
        return ($effects[EffectType::MINING_FATIGUE->value] ?? null)?->level() ?? 0;
    }

    /** @param array<string, EffectInstance> $effects */
    public static function fallDamage(array $effects, float $fallDistance): float
    {
        if (isset($effects[EffectType::SLOW_FALLING->value])) {
            return 0.0;
        }
        $jump = $effects[EffectType::JUMP_BOOST->value] ?? null;
        $safeDistance = 3.0 + ($jump instanceof EffectInstance ? $jump->level() : 0);

        return max(0.0, ceil($fallDistance - $safeDistance));
    }

    /** @param array<string, EffectInstance> $effects */
    public static function hasFireResistance(array $effects): bool
    {
        return isset($effects[EffectType::FIRE_RESISTANCE->value]);
    }

    /** @param array<string, EffectInstance> $effects */
    public static function canBreatheUnderwater(array $effects): bool
    {
        return isset($effects[EffectType::WATER_BREATHING->value])
            || isset($effects[EffectType::CONDUIT_POWER->value]);
    }

    /** @param array<string, EffectInstance> $effects */
    public static function maximumHealth(array $effects): float
    {
        $boost = $effects[EffectType::HEALTH_BOOST->value] ?? null;

        return 20.0 + ($boost instanceof EffectInstance ? 4.0 * $boost->level() : 0.0);
    }

    /** @param array<string, EffectInstance> $effects */
    public static function absorptionCapacity(array $effects): float
    {
        $absorption = $effects[EffectType::ABSORPTION->value] ?? null;

        return $absorption instanceof EffectInstance ? 4.0 * $absorption->level() : 0.0;
    }

    /** @param array<string, EffectInstance> $effects */
    public static function levitationVelocity(array $effects, float $currentVelocity): ?float
    {
        $levitation = $effects[EffectType::LEVITATION->value] ?? null;
        if (!$levitation instanceof EffectInstance) {
            return null;
        }

        return $currentVelocity + ((($levitation->level() / 20.0) - $currentVelocity) / 5.0);
    }

    /** @param array<string, EffectInstance> $effects */
    public static function movementValidationMultiplier(array $effects): float
    {
        $jump = $effects[EffectType::JUMP_BOOST->value] ?? null;
        $vertical = $jump instanceof EffectInstance ? 1.0 + ($jump->level() / 10.0) : 1.0;
        if (isset($effects[EffectType::LEVITATION->value])) {
            $vertical = max($vertical, 2.0);
        }

        return max(self::movementMultiplier($effects), $vertical);
    }
}
