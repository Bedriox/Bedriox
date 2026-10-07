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
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;

/** Hurt cubs escape from the nearest player but never acquire an attack target. */
final readonly class PolarBearCubFleeGoal implements AiGoal
{
    public function identifier(): string
    {
        return 'bedriox:polar_bear_cub_flee';
    }

    public function priority(): int
    {
        return 120;
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
        return $this->target($entity, $memory, $context) !== null
            && $memory->contains(VanillaAiMemories::hurt(), $context->tick);
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->flee($entity, $memory, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->flee($entity, $memory, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function flee(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $target = $this->target($entity, $memory, $context);
        if ($target !== null) {
            HorizontalSteering::away($entity, $target->position, 0.20, $context->tick, $context->world);
        }
    }

    private function target(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        if (!$entity instanceof PolarBearEntity || !$entity->isAlive() || !$entity->isBaby()) {
            return null;
        }
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);

        return $target instanceof AiPlayerSnapshot ? $target : null;
    }
}
