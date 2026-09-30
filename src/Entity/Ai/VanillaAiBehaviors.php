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

use Bedriox\Server\Entity\Ai\Goal\ChasePlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\FleeFromPlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\MeleeAttackIntentGoal;
use Bedriox\Server\Entity\Ai\Goal\RangedAttackIntentGoal;
use Bedriox\Server\Entity\Ai\Goal\TemptedByItemGoal;
use Bedriox\Server\Entity\Ai\Goal\WanderGoal;
use Bedriox\Server\Entity\Ai\Sensor\HurtSensor;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;
use Bedriox\Server\Entity\Ai\Sensor\TemptingPlayerSensor;

final class VanillaAiBehaviors
{
    private static ?AiBehaviorDefinition $cow = null;
    private static ?AiBehaviorDefinition $sheep = null;
    private static ?AiBehaviorDefinition $skeleton = null;
    private static ?AiBehaviorDefinition $zombie = null;

    public static function cow(): AiBehaviorDefinition
    {
        return self::$cow ??= new AiBehaviorDefinition(
            sensors: [
                new NearestPlayerSensor('bedriox:cow_nearest_player', 10, 16.0, 30),
                new HurtSensor('bedriox:cow_hurt', 60),
            ],
            goals: [
                new FleeFromPlayerGoal('bedriox:cow_flee', 100, 0.18),
                new WanderGoal('bedriox:cow_wander', 10, 40, 35, 0.075),
            ],
        );
    }

    public static function zombie(): AiBehaviorDefinition
    {
        return self::$zombie ??= new AiBehaviorDefinition(
            sensors: [
                new NearestPlayerSensor('bedriox:zombie_nearest_player', 10, 32.0, 30),
            ],
            goals: [
                new MeleeAttackIntentGoal('bedriox:zombie_melee', 100, 1.8, 20, 3.0),
                new ChasePlayerGoal('bedriox:zombie_chase', 80, 1.8, 0.11),
                new WanderGoal('bedriox:zombie_wander', 10, 30, 40, 0.085),
            ],
        );
    }

    public static function sheep(): AiBehaviorDefinition
    {
        return self::$sheep ??= new AiBehaviorDefinition(
            sensors: [
                new NearestPlayerSensor('bedriox:sheep_nearest_player', 10, 10.0, 30),
                new TemptingPlayerSensor('bedriox:sheep_wheat', 5, 10.0, 'minecraft:wheat', 10),
                new HurtSensor('bedriox:sheep_hurt', 60),
            ],
            goals: [
                new FleeFromPlayerGoal('bedriox:sheep_flee', 100, 0.18),
                new TemptedByItemGoal('bedriox:sheep_follow_wheat', 80, 2.0, 0.10),
                new WanderGoal('bedriox:sheep_wander', 10, 40, 35, 0.075),
            ],
        );
    }

    public static function skeleton(): AiBehaviorDefinition
    {
        return self::$skeleton ??= new AiBehaviorDefinition(
            sensors: [
                new NearestPlayerSensor('bedriox:skeleton_nearest_player', 10, 15.0, 30),
            ],
            goals: [
                new RangedAttackIntentGoal(
                    'bedriox:skeleton_ranged_attack',
                    100,
                    5.0,
                    15.0,
                    60,
                    1.6,
                    0.1,
                ),
                new WanderGoal('bedriox:skeleton_wander', 10, 30, 40, 0.08),
            ],
        );
    }
}
