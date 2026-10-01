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
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\EntityMotion;

/** A bounded ground hop that gives rabbits species-appropriate locomotion. */
final readonly class RabbitHopGoal implements AiGoal
{
    public function identifier(): string
    {
        return 'bedriox:rabbit_hop';
    }
    public function priority(): int
    {
        return 15;
    }
    public function evaluationIntervalTicks(): int
    {
        return 1;
    }
    public function controls(): array
    {
        return [AiControl::MOVE];
    }
    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $entity->isAlive() && $entity->isOnGround() && ($context->tick + $entity->getRuntimeId()) % 12 === 0;
    }
    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return false;
    }
    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $angle = fmod(($context->tick * 37.0) + ($entity->getRuntimeId() * 71.0), 360.0) * M_PI / 180.0;
        $entity->applyAiMotion(new EntityMotion(cos($angle) * 0.12, 0.32, sin($angle) * 0.12), $context->tick);
    }
    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void {}
    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void {}
}
