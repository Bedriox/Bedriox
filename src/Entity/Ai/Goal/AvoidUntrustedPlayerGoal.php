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

use Bedriox\Api\Entity\Capability\Trusting;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

/** Vanilla avoidance for animals which become calm around explicitly trusted players. */
final readonly class AvoidUntrustedPlayerGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $speed,
        private float $minimumDistance,
    ) {
        if (!is_finite($speed) || $speed <= 0.0 || $speed > 1.0
            || !is_finite($minimumDistance) || $minimumDistance <= 0.0 || $minimumDistance > 32.0) {
            throw new InvalidArgumentException('Untrusted-player avoidance bounds are invalid.');
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
        return [AiControl::MOVE, AiControl::LOOK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->target($entity, $memory, $context) !== null;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->target($entity, $memory, $context) !== null;
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
        $target = $this->target($entity, $memory, $context);
        if ($target !== null) {
            HorizontalSteering::away($entity, $target->position, $this->speed, $context->tick, $context->world);
        }
    }

    private function target(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): ?AiPlayerSnapshot
    {
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);
        if (!$entity instanceof Trusting || !$target instanceof AiPlayerSnapshot
            || $entity->trustsPlayer($target->playerId)
            || $target->distanceSquaredTo($entity->internalPosition()) > $this->minimumDistance ** 2) {
            return null;
        }

        return $target;
    }
}
