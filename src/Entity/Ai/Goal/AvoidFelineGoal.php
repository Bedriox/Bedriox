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

use Bedriox\Api\Entity\Capability\FelineDeterrent;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;

/** Bounded vanilla creeper avoidance of nearby cats and ocelots. */
final readonly class AvoidFelineGoal implements AiGoal
{
    private const float AVOIDANCE_RADIUS = 6.0;
    private const int MAXIMUM_CANDIDATES = 12;

    public function identifier(): string
    {
        return 'bedriox:creeper_avoid_feline';
    }

    public function priority(): int
    {
        return 120;
    }

    public function evaluationIntervalTicks(): int
    {
        return 2;
    }

    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK, AiControl::TARGET];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $entity->isAlive() && $this->nearestFeline($entity, $context) !== null;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->steer($entity, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function steer(AbstractMobEntity $entity, AiTickContext $context): void
    {
        $feline = $this->nearestFeline($entity, $context);
        if ($feline !== null) {
            HorizontalSteering::away($entity, $feline->internalPosition(), 0.16, $context->tick, $context->world);
        }
    }

    private function nearestFeline(AbstractMobEntity $entity, AiTickContext $context): ?AbstractEntity
    {
        $nearest = null;
        $nearestDistance = self::AVOIDANCE_RADIUS ** 2;
        foreach ($context->world->nearbyEntities($entity, self::AVOIDANCE_RADIUS, self::MAXIMUM_CANDIDATES) as $candidate) {
            if (!$candidate instanceof FelineDeterrent || !$candidate->isAlive()) {
                continue;
            }
            $distance = $candidate->internalPosition()->distanceTo($entity->internalPosition()) ** 2;
            if ($distance < $nearestDistance
                || ($distance === $nearestDistance
                    && ($nearest === null || $candidate->getRuntimeId() < $nearest->getRuntimeId()))) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }
}
