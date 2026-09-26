<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\Ai\Goal\ChasePlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\FleeFromPlayerGoal;
use Bedriox\Server\Entity\Ai\Goal\MeleeAttackIntentGoal;
use Bedriox\Server\Entity\Ai\Goal\WanderGoal;
use Bedriox\Server\Entity\Ai\Sensor\HurtSensor;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;

final class VanillaAiBehaviors
{
    private static ?AiBehaviorDefinition $cow = null;
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
}
