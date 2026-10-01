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

namespace Bedriox\Server\Entity\Ai\Goal;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AquaticAiWorldView;
use Bedriox\Server\Entity\Ai\AquaticNavigation;
use Bedriox\Server\Entity\Ai\AquaticSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\AquaticRuntimeState;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final readonly class AquaticChasePlayerGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $stoppingDistance,
        private float $speed,
    ) {
        if (!is_finite($stoppingDistance) || $stoppingDistance <= 0.0 || $stoppingDistance > 16.0
            || !is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Aquatic chase bounds are invalid.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
    public function priority(): int
    {
        return $this->priority;
    }
    public function evaluationIntervalTicks(): int
    {
        return 1;
    }
    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK, AiControl::TARGET];
    }
    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $target = $this->target($entity, $memory, $context);
        return $target !== null && $target->distanceSquaredTo($entity->internalPosition()) > $this->stoppingDistance ** 2;
    }
    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }
    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $memory, $context);
    }
    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $memory, $context);
    }
    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        AquaticSteering::stop($entity, $context->tick);
    }

    private function steer(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $target = $this->target($entity, $memory, $context);
        if ($target === null) {
            return;
        }
        $position = $entity->internalPosition();
        $inWater = !$context->world instanceof AquaticAiWorldView
            || $context->world->isTouchingWater($entity);
        $landNavigation = !$inWater
            && $entity instanceof AquaticRuntimeState
            && $entity->canNavigateOnLand();
        if (!$inWater && !$landNavigation) {
            AquaticSteering::stop($entity, $context->tick);
            return;
        }
        $dx = $target->position->x - $position->x;
        $dy = $landNavigation
            ? 0.0
            : ($target->position->y + 0.9) - ($position->y + ($entity->collisionHeight() / 2.0));
        $dz = $target->position->z - $position->z;
        $length = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));
        if ($length < 0.000_001) {
            return;
        }
        $motionX = ($dx / $length) * $this->speed;
        $motionY = ($dy / $length) * $this->speed;
        $motionZ = ($dz / $length) * $this->speed;
        if ($inWater && $context->world instanceof AquaticAiWorldView) {
            $next = new Position(
                $position->x + ($motionX * 8.0),
                $position->y + ($motionY * 8.0),
                $position->z + ($motionZ * 8.0),
            );
            if (!AquaticNavigation::hasNavigableWaterAt($context->world, $entity, $next)) {
                $recovery = AquaticNavigation::recoveryMotion(
                    $context->world,
                    $entity,
                    $this->speed,
                    (($entity->getRuntimeId() * 31) ^ $context->tick) & 0x7fffffff,
                );
                $motionX = $recovery->x;
                $motionY = $recovery->y;
                $motionZ = $recovery->z;
            }
        }
        AquaticSteering::motion(
            $entity,
            $motionX,
            $motionY,
            $motionZ,
            $context->tick,
            !$landNavigation,
        );
    }

    private function target(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): ?AiPlayerSnapshot
    {
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);
        return $entity->isAlive() && $target instanceof AiPlayerSnapshot ? $target : null;
    }
}
