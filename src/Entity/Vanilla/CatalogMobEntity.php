<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;

/** Shared implementation for catalog-admitted vanilla mobs without specialized server state yet. */
final class CatalogMobEntity extends AbstractMobEntity
{
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            $definition->category === EntityCategory::MONSTER
                ? VanillaAiBehaviors::zombie()
                : VanillaAiBehaviors::cow(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }
}
