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
use Bedriox\Server\Entity\Ai\AiMeleeIntent;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use InvalidArgumentException;

/** Emits close-range attacks without freezing concurrent aquatic steering. */
final readonly class AquaticMeleeAttackIntentGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $reach,
        private int $cooldownTicks,
        private float $damage,
    ) {
        if (!is_finite($reach) || $reach <= 0.0 || $reach > 16.0
            || $cooldownTicks < 1 || $cooldownTicks > 1_200
            || !is_finite($damage) || $damage <= 0.0 || $damage > 1_000.0) {
            throw new InvalidArgumentException('Aquatic melee attack bounds are invalid.');
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
        return [AiControl::ATTACK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->targetInReach($entity, $memory, $context) !== null;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        return $this->canStart($entity, $memory, $context);
    }

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->emitIntent($entity, $memory, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->emitIntent($entity, $memory, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void {}

    private function emitIntent(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        if ($memory->contains(VanillaAiMemories::meleeCooldown(), $context->tick)) {
            return;
        }
        $target = $this->targetInReach($entity, $memory, $context);
        if ($target === null) {
            return;
        }
        $memory->put(
            VanillaAiMemories::meleeIntent(),
            new AiMeleeIntent($target->playerId, $context->tick, $this->reach, $this->damage),
            $context->tick + 2,
        );
        $memory->put(VanillaAiMemories::meleeCooldown(), true, $context->tick + $this->cooldownTicks);
    }

    private function targetInReach(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);
        if (!$entity->isAlive() || !$target instanceof AiPlayerSnapshot
            || $target->distanceSquaredTo($entity->internalPosition()) > $this->reach ** 2) {
            return null;
        }

        return $target;
    }
}
