<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use Bedriox\Api\World\Position;
use LogicException;

/** @internal */
final class UnavailableEntityRegistrar implements EntityRegistrar
{
    public function register(CustomMobDefinition $definition, bool $replace = false): void
    {
        throw new LogicException('Entity registration is unavailable in this plugin context.');
    }

    public function spawn(CustomEntityType $type, Position $position, float $yaw = 0.0, float $pitch = 0.0): void
    {
        throw new LogicException('Entity spawning is unavailable in this plugin context.');
    }
}
