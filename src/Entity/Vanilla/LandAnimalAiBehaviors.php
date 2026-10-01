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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\Goal\ChasePlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\FleeFromPlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\FollowOwnerGoal;
use Bedriox\Server\Entity\Ai\Goal\MeleeAttackIntentGoal;
use Bedriox\Server\Entity\Ai\Goal\TemptedByItemGoal;
use Bedriox\Server\Entity\Ai\Goal\WanderGoal;
use Bedriox\Server\Entity\Ai\Sensor\AngerTargetSensor;
use Bedriox\Server\Entity\Ai\Sensor\HurtSensor;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;
use Bedriox\Server\Entity\Ai\Sensor\TemptingPlayerSensor;

/** Basic bounded behavior used until each species' specialized goals are available. */
final class LandAnimalAiBehaviors
{
    /** @var array<string, AiBehaviorDefinition> */
    private static array $behaviors = [];

    /** @param list<string> $foods */
    public static function passive(string $species, array $foods, float $speed): AiBehaviorDefinition
    {
        $key = $species . ':' . implode(',', $foods);

        return self::$behaviors[$key] ??= new AiBehaviorDefinition(
            sensors: [
                new NearestPlayerSensor("bedriox:{$species}_nearest_player", 10, 12.0, 30),
                new TemptingPlayerSensor("bedriox:{$species}_food", 5, 10.0, $foods, 10),
                new HurtSensor("bedriox:{$species}_hurt", 60),
            ],
            goals: [
                new FleeFromPlayerGoal("bedriox:{$species}_flee", 100, max(0.18, $speed + 0.06)),
                new TemptedByItemGoal("bedriox:{$species}_follow_food", 80, 2.0, $speed),
                new WanderGoal("bedriox:{$species}_wander", 10, 40, 35, $speed * 0.75),
            ],
        );
    }

    public static function wandering(string $species, float $speed): AiBehaviorDefinition
    {
        return self::$behaviors['wandering:' . $species] ??= new AiBehaviorDefinition(
            sensors: [new HurtSensor("bedriox:{$species}_hurt", 60)],
            goals: [
                new FleeFromPlayerGoal("bedriox:{$species}_flee", 100, max(0.18, $speed + 0.06)),
                new WanderGoal("bedriox:{$species}_wander", 10, 40, 35, $speed * 0.75),
            ],
        );
    }

    /** @param list<string> $foods */
    public static function companion(string $species, array $foods, float $speed): AiBehaviorDefinition
    {
        $key = 'companion:' . $species . ':' . implode(',', $foods);

        return self::$behaviors[$key] ??= new AiBehaviorDefinition(
            sensors: [
                new TemptingPlayerSensor("bedriox:{$species}_food", 5, 10.0, $foods, 10),
                new HurtSensor("bedriox:{$species}_hurt", 60),
            ],
            goals: [
                new FleeFromPlayerGoal("bedriox:{$species}_flee", 100, max(0.18, $speed + 0.06)),
                new FollowOwnerGoal("bedriox:{$species}_follow_owner", 90, 6.0, 2.0, $speed),
                new TemptedByItemGoal("bedriox:{$species}_follow_food", 80, 2.0, $speed),
                new WanderGoal("bedriox:{$species}_wander", 10, 40, 35, $speed * 0.75),
            ],
        );
    }

    public static function wolf(): AiBehaviorDefinition
    {
        return self::$behaviors['wolf'] ??= new AiBehaviorDefinition(
            sensors: [
                new AngerTargetSensor('bedriox:wolf_anger_target'),
                new TemptingPlayerSensor('bedriox:wolf_food', 5, 10.0, ['minecraft:bone'], 10),
                new HurtSensor('bedriox:wolf_hurt', 60),
            ],
            goals: [
                new MeleeAttackIntentGoal('bedriox:wolf_attack', 120, 1.8, 20, 4.0),
                new ChasePlayerGoal('bedriox:wolf_chase', 110, 1.5, 0.15),
                new FollowOwnerGoal('bedriox:wolf_follow_owner', 90, 6.0, 2.0, 0.14),
                new TemptedByItemGoal('bedriox:wolf_follow_bone', 80, 2.0, 0.12),
                new WanderGoal('bedriox:wolf_wander', 10, 40, 35, 0.09),
            ],
        );
    }
}
