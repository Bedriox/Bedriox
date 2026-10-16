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
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\Vanilla\IronGolemEntity;

/** Pursues the authoritative anger target and preserves the golem's visible attack wind-up. */
final readonly class IronGolemAttackGoal implements AiGoal
{
    /** Distance at which the golem plants its feet and begins the visible swing. */
    private const float WIND_UP_DISTANCE = 1.65;
    /** The target may move slightly during the swing without invalidating the impact. */
    private const float IMPACT_REACH = 2.8;
    private const float CHASE_SPEED = 0.15;
    private const int WIND_UP_TICKS = 7;
    private const int COOLDOWN_TICKS = 20;

    public function identifier(): string
    {
        return 'bedriox:iron_golem_attack';
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
        return [AiControl::MOVE, AiControl::LOOK, AiControl::TARGET, AiControl::ATTACK];
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
        $this->pursueOrAttack($entity, $memory, $context);
    }

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $this->pursueOrAttack($entity, $memory, $context);
    }

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void
    {
        $memory->forget(VanillaAiMemories::ironGolemAttackAt());
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function pursueOrAttack(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): void {
        $target = $this->target($entity, $memory, $context);
        if ($target === null) {
            $this->stop($entity, $memory, $context);
            return;
        }
        if ($target->distanceSquaredTo($entity->internalPosition()) > self::WIND_UP_DISTANCE ** 2) {
            $memory->forget(VanillaAiMemories::ironGolemAttackAt());
            HorizontalSteering::toward(
                $entity,
                $target->position,
                self::CHASE_SPEED,
                $context->tick,
                $context->world,
            );
            return;
        }

        HorizontalSteering::stop($entity, $context->tick);
        if ($memory->contains(VanillaAiMemories::meleeCooldown(), $context->tick)) {
            return;
        }
        $attackAt = $memory->get(VanillaAiMemories::ironGolemAttackAt(), $context->tick);
        if (!is_int($attackAt)) {
            $memory->put(VanillaAiMemories::ironGolemAttackAt(), $context->tick + self::WIND_UP_TICKS);
            return;
        }
        if ($context->tick < $attackAt) {
            return;
        }
        $memory->forget(VanillaAiMemories::ironGolemAttackAt());
        $memory->put(
            VanillaAiMemories::meleeIntent(),
            new AiMeleeIntent($target->playerId, $context->tick, self::IMPACT_REACH, 7.0),
            $context->tick + 2,
        );
        $memory->put(VanillaAiMemories::meleeCooldown(), true, $context->tick + self::COOLDOWN_TICKS);
    }

    private function target(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        if (!$entity instanceof IronGolemEntity
            || !$entity->isAlive() || $entity->getRemainingAngerTicks() < 1) {
            return null;
        }
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);

        return $target instanceof AiPlayerSnapshot
            && $target->playerId === $entity->getAngerTargetUniqueId()
            ? $target
            : null;
    }
}
