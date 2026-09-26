<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;

final class HorizontalSteering
{
    public static function toward(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick = null,
    ): void {
        self::apply($entity, $target, $speed, $aiTick);
    }

    public static function away(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick = null,
    ): void {
        self::apply($entity, $target, -$speed, $aiTick);
    }

    public static function stop(AbstractMobEntity $entity, ?int $aiTick = null): void
    {
        self::setMotion($entity, new EntityMotion(0.0, $entity->getMotion()->y, 0.0), $aiTick);
    }

    public static function motion(AbstractMobEntity $entity, float $x, float $z, ?int $aiTick = null): void
    {
        if (!self::setMotion($entity, new EntityMotion($x, $entity->getMotion()->y, $z), $aiTick)) {
            return;
        }
        if (hypot($x, $z) < 0.000_001) {
            return;
        }
        $entity->moveTo(
            $entity->getWorldName(),
            $entity->internalPosition(),
            rad2deg(atan2(-$x, $z)),
            $entity->getPitch(),
        );
    }

    private static function apply(
        AbstractMobEntity $entity,
        Position $target,
        float $speed,
        ?int $aiTick,
    ): void {
        $position = $entity->internalPosition();
        $dx = $target->x - $position->x;
        $dz = $target->z - $position->z;
        $length = hypot($dx, $dz);
        if ($length < 0.000_001) {
            self::stop($entity, $aiTick);

            return;
        }
        self::motion(
            $entity,
            ($dx / $length) * $speed,
            ($dz / $length) * $speed,
            $aiTick,
        );
    }

    private static function setMotion(AbstractMobEntity $entity, EntityMotion $motion, ?int $aiTick): bool
    {
        if ($aiTick !== null) {
            return $entity->applyAiMotion($motion, $aiTick);
        }
        $entity->setMotion($motion);

        return true;
    }
}
