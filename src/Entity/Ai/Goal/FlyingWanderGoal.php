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
use Bedriox\Server\Entity\Ai\FlightSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class FlyingWanderGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority = 10,
        private float $speed = 0.08,
    ) {
        if ($identifier === '' || !is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Flying wander bounds are invalid.');
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
        return 20;
    }

    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $entity->isAlive() && !$memory->contains(VanillaAiMemories::wanderUntil(), $context->tick);
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $until = $memory->get(VanillaAiMemories::wanderUntil(), $context->tick);
        return $entity->isAlive() && is_int($until) && $context->tick < $until;
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $seed = (($entity->getRuntimeId() * 1_103_515_245) ^ ($context->tick * 12_345)) & 0x7fffffff;
        $angle = ($seed % 6_283) / 1_000.0;
        $vertical = (((($seed >> 7) % 101) - 50) / 50.0) * $this->speed;
        $memory->put(VanillaAiMemories::wanderUntil(), $context->tick + 40 + ($seed % 41));
        $memory->put(VanillaAiMemories::wanderMotionX(), cos($angle) * $this->speed);
        $memory->put(VanillaAiMemories::aquaticWanderMotionY(), $vertical);
        $memory->put(VanillaAiMemories::wanderMotionZ(), sin($angle) * $this->speed);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $x = $memory->get(VanillaAiMemories::wanderMotionX(), $context->tick);
        $y = $memory->get(VanillaAiMemories::aquaticWanderMotionY(), $context->tick);
        $z = $memory->get(VanillaAiMemories::wanderMotionZ(), $context->tick);
        if (is_float($x) && is_float($y) && is_float($z)) {
            FlightSteering::motion($entity, $x, $y, $z, $this->speed, $context->tick);
        }
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $memory->forget(VanillaAiMemories::wanderUntil());
        $memory->forget(VanillaAiMemories::wanderMotionX());
        $memory->forget(VanillaAiMemories::aquaticWanderMotionY());
        $memory->forget(VanillaAiMemories::wanderMotionZ());
        FlightSteering::stop($entity, $context->tick);
    }
}
