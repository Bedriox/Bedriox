<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

final class PlayerCollisionShape
{
    public const float WIDTH = 0.6;
    public const float HEIGHT = 1.8;
    public const float STEP_HEIGHT = 0.6;

    private function __construct() {}

    public static function at(Position $feet): AxisAlignedBox
    {
        $radius = self::WIDTH / 2.0;

        return new AxisAlignedBox(
            $feet->x - $radius,
            $feet->y,
            $feet->z - $radius,
            $feet->x + $radius,
            $feet->y + self::HEIGHT,
            $feet->z + $radius,
        );
    }
}
