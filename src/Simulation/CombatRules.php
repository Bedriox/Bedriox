<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

/** Stable vanilla combat mechanics; these are not operator configuration. */
final class CombatRules
{
    public const float MAXIMUM_ENTITY_REACH = 8.0;
    public const float EMPTY_HAND_DAMAGE = 1.0;
    public const int DAMAGE_IMMUNITY_TICKS = 10;
    public const float KNOCKBACK_FORCE = 0.4;
    public const float KNOCKBACK_VERTICAL_LIMIT = 0.4;

    private function __construct() {}
}
