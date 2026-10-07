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

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Simulation\Position;

final readonly class WorriedPandaAvoidThreatGoal implements AiGoal
{
    private const float AVOIDANCE_RADIUS = 8.0;
    private const int MAXIMUM_ENTITY_CANDIDATES = 32;

    public function identifier(): string
    {
        return 'bedriox:panda_worried_avoid_threat';
    }

    public function priority(): int
    {
        return 130;
    }

    public function evaluationIntervalTicks(): int
    {
        return 1;
    }

    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->targetPosition($entity, $memory, $context) !== null;
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
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function steer(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $target = $this->targetPosition($entity, $memory, $context);
        if ($target !== null) {
            HorizontalSteering::away($entity, $target, 0.16, $context->tick, $context->world);
        }
    }

    private function targetPosition(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?Position {
        if (!$entity instanceof PandaEntity || $entity->getExpressedGene() !== PandaGene::WORRIED) {
            return null;
        }
        $origin = $entity->internalPosition();
        $maximumDistanceSquared = self::AVOIDANCE_RADIUS ** 2;
        $player = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);
        $target = $player instanceof AiPlayerSnapshot
            && $player->worldName === $entity->getWorldName()
            && $player->distanceSquaredTo($origin) <= $maximumDistanceSquared
                ? $player->position
                : null;
        $targetDistanceSquared = $target === null ? null : $target->distanceTo($origin) ** 2;
        $hostileTarget = null;
        $hostileDistanceSquared = null;
        $hostileRuntimeId = null;

        foreach ($context->world->nearbyEntities(
            $entity,
            self::AVOIDANCE_RADIUS,
            self::MAXIMUM_ENTITY_CANDIDATES,
        ) as $candidate) {
            if (!$candidate instanceof AbstractLivingEntity || !$candidate->isAlive()
                || $candidate->getCategory() !== EntityCategory::MONSTER) {
                continue;
            }
            $candidatePosition = $candidate->internalPosition();
            $candidateDistanceSquared = $candidatePosition->distanceTo($origin) ** 2;
            if ($hostileDistanceSquared !== null
                && ($candidateDistanceSquared > $hostileDistanceSquared
                    || ($candidateDistanceSquared === $hostileDistanceSquared
                        && $hostileRuntimeId !== null
                        && $candidate->getRuntimeId() >= $hostileRuntimeId))) {
                continue;
            }
            $hostileTarget = $candidatePosition;
            $hostileDistanceSquared = $candidateDistanceSquared;
            $hostileRuntimeId = $candidate->getRuntimeId();
        }
        if ($hostileTarget !== null
            && ($targetDistanceSquared === null || $hostileDistanceSquared < $targetDistanceSquared)) {
            $target = $hostileTarget;
        }

        return $target;
    }
}
