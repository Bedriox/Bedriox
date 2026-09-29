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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Effect\EffectType;
use Bedriox\Protocol\Packet\MobEffectType;

/** Exhaustive domain-to-wire translation for the current supported Bedrock release. */
final readonly class BedrockEffectTranslator
{
    public static function type(EffectType $type): MobEffectType
    {
        return match ($type) {
            EffectType::SPEED => MobEffectType::Speed,
            EffectType::SLOWNESS => MobEffectType::Slowness,
            EffectType::HASTE => MobEffectType::Haste,
            EffectType::MINING_FATIGUE => MobEffectType::MiningFatigue,
            EffectType::STRENGTH => MobEffectType::Strength,
            EffectType::INSTANT_HEALTH => MobEffectType::InstantHealth,
            EffectType::INSTANT_DAMAGE => MobEffectType::InstantDamage,
            EffectType::JUMP_BOOST => MobEffectType::JumpBoost,
            EffectType::NAUSEA => MobEffectType::Nausea,
            EffectType::REGENERATION => MobEffectType::Regeneration,
            EffectType::RESISTANCE => MobEffectType::Resistance,
            EffectType::FIRE_RESISTANCE => MobEffectType::FireResistance,
            EffectType::WATER_BREATHING => MobEffectType::WaterBreathing,
            EffectType::INVISIBILITY => MobEffectType::Invisibility,
            EffectType::BLINDNESS => MobEffectType::Blindness,
            EffectType::NIGHT_VISION => MobEffectType::NightVision,
            EffectType::HUNGER => MobEffectType::Hunger,
            EffectType::WEAKNESS => MobEffectType::Weakness,
            EffectType::POISON => MobEffectType::Poison,
            EffectType::WITHER => MobEffectType::Wither,
            EffectType::HEALTH_BOOST => MobEffectType::HealthBoost,
            EffectType::ABSORPTION => MobEffectType::Absorption,
            EffectType::SATURATION => MobEffectType::Saturation,
            EffectType::LEVITATION => MobEffectType::Levitation,
            EffectType::FATAL_POISON => MobEffectType::FatalPoison,
            EffectType::CONDUIT_POWER => MobEffectType::ConduitPower,
            EffectType::SLOW_FALLING => MobEffectType::SlowFalling,
            EffectType::BAD_OMEN => MobEffectType::BadOmen,
            EffectType::VILLAGE_HERO => MobEffectType::VillageHero,
            EffectType::DARKNESS => MobEffectType::Darkness,
            EffectType::TRIAL_OMEN => MobEffectType::TrialOmen,
            EffectType::WIND_CHARGED => MobEffectType::WindCharged,
            EffectType::WEAVING => MobEffectType::Weaving,
            EffectType::OOZING => MobEffectType::Oozing,
            EffectType::INFESTED => MobEffectType::Infested,
        };
    }
}
