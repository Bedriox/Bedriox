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
use Bedriox\Server\Entity\Ai\AiRangedIntent;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

final readonly class StationaryRangedAttackIntentGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $maximumRange,
        private int $cooldownTicks,
        private float $projectileSpeed,
    ) {
        if ($identifier === '' || strlen($identifier) > 128 || $priority < 0
            || !is_finite($maximumRange) || $maximumRange <= 0.0 || $maximumRange > 128.0
            || $cooldownTicks < 1 || $cooldownTicks > 1_200
            || !is_finite($projectileSpeed) || $projectileSpeed < 0.1 || $projectileSpeed > 3.2) {
            throw new InvalidArgumentException('Stationary ranged goal bounds are invalid.');
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
        return [AiControl::LOOK, AiControl::TARGET, AiControl::ATTACK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->target($entity, $memory, $context) !== null;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->update($entity, $memory, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->update($entity, $memory, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void {}

    private function update(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $target = $this->target($entity, $memory, $context);
        if ($target === null || $target->distanceSquaredTo($entity->internalPosition()) > $this->maximumRange ** 2
            || $memory->contains(VanillaAiMemories::rangedCooldown(), $context->tick)) {
            return;
        }
        $memory->put(
            VanillaAiMemories::rangedIntent(),
            new AiRangedIntent($target->playerId, $context->tick, $this->maximumRange, $this->projectileSpeed),
            $context->tick + 2,
        );
        $memory->put(VanillaAiMemories::rangedCooldown(), true, $context->tick + $this->cooldownTicks);
    }

    private function target(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);

        return $entity->isAlive() && $target instanceof AiPlayerSnapshot ? $target : null;
    }
}
