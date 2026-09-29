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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Potion\PotionType;
use InvalidArgumentException;

/** Vanilla scaling rules shared by drinkable, splash, lingering, and tipped-arrow delivery. */
final readonly class PotionEffectProjector
{
    /** @return list<PotionEffectDose> */
    public function drink(PotionType $type): array
    {
        return array_map(static fn(EffectInstance $effect): PotionEffectDose => new PotionEffectDose($effect), $type->effects());
    }

    /** @return list<PotionEffectDose> */
    public function splash(PotionType $type, float $distanceBlocks, bool $directHit = false): array
    {
        if (!is_finite($distanceBlocks) || $distanceBlocks < 0.0) {
            throw new InvalidArgumentException('Splash-potion distance must be finite and non-negative.');
        }
        if ($distanceBlocks > 4.0) {
            return [];
        }
        $intensity = $directHit ? 1.0 : max(0.0, 1.0 - ($distanceBlocks / 4.0));

        return $this->scaled($type, $intensity, 0.75, 20);
    }

    /** @return list<PotionEffectDose> */
    public function lingering(PotionType $type): array
    {
        $doses = [];
        foreach ($type->effects() as $effect) {
            if (self::isInstant($effect->type)) {
                $doses[] = new PotionEffectDose($effect, 0.5);
                continue;
            }
            $doses[] = new PotionEffectDose(new EffectInstance(
                $effect->type,
                max(1, (int) round($effect->durationTicks * 0.25)),
                $effect->amplifier,
                $effect->visible,
                $effect->ambient,
                $effect->infinite,
            ));
        }

        return $doses;
    }

    /** @return list<PotionEffectDose> */
    public function tippedArrow(PotionType $type): array
    {
        return $this->scaled($type, 1.0, 0.125, 1);
    }

    /** @return list<PotionEffectDose> */
    private function scaled(PotionType $type, float $intensity, float $durationScale, int $minimumDuration): array
    {
        $doses = [];
        foreach ($type->effects() as $effect) {
            if (self::isInstant($effect->type)) {
                $doses[] = new PotionEffectDose($effect, $intensity);
                continue;
            }
            $duration = (int) round($effect->durationTicks * $durationScale * $intensity);
            if ($duration < $minimumDuration) {
                continue;
            }
            $doses[] = new PotionEffectDose(new EffectInstance(
                $effect->type,
                $duration,
                $effect->amplifier,
                $effect->visible,
                $effect->ambient,
                $effect->infinite,
            ), $intensity);
        }

        return $doses;
    }

    public static function isInstant(EffectType $type): bool
    {
        return $type === EffectType::INSTANT_HEALTH || $type === EffectType::INSTANT_DAMAGE;
    }
}
