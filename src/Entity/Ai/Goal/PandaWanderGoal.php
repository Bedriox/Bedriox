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

use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\PandaEntity;

final readonly class PandaWanderGoal implements AiGoal
{
    public function identifier(): string
    {
        return 'bedriox:panda_wander';
    }

    public function priority(): int
    {
        return 10;
    }

    public function evaluationIntervalTicks(): int
    {
        return 40;
    }

    public function controls(): array
    {
        return [AiControl::MOVE];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $entity instanceof PandaEntity
            && $entity->isAlive()
            && $entity->getActivity() === PandaActivity::IDLE
            && !$memory->contains(VanillaAiMemories::wanderUntil(), $context->tick);
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $until = $memory->get(VanillaAiMemories::wanderUntil(), $context->tick);

        return $entity instanceof PandaEntity && $entity->isAlive()
            && $entity->getActivity() === PandaActivity::IDLE
            && is_int($until) && $context->tick < $until;
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if (!$entity instanceof PandaEntity) {
            return;
        }
        $seed = (($entity->getRuntimeId() * 1_103_515_245) ^ ($context->tick * 12_345)) & 0x7fffffff;
        $angle = ($seed % 6_283) / 1_000.0;
        $speed = match ($entity->getExpressedGene()) {
            PandaGene::LAZY => 0.03,
            PandaGene::PLAYFUL => 0.075,
            default => 0.06,
        };
        $memory->put(VanillaAiMemories::wanderUntil(), $context->tick + 30);
        $memory->put(VanillaAiMemories::wanderMotionX(), cos($angle) * $speed);
        $memory->put(VanillaAiMemories::wanderMotionZ(), sin($angle) * $speed);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $x = $memory->get(VanillaAiMemories::wanderMotionX(), $context->tick);
        $z = $memory->get(VanillaAiMemories::wanderMotionZ(), $context->tick);
        if (is_float($x) && is_float($z)) {
            HorizontalSteering::motion($entity, $x, $z, $context->tick, $context->world);
        }
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $memory->forget(VanillaAiMemories::wanderUntil());
        $memory->forget(VanillaAiMemories::wanderMotionX());
        $memory->forget(VanillaAiMemories::wanderMotionZ());
        HorizontalSteering::stop($entity, $context->tick);
    }
}
