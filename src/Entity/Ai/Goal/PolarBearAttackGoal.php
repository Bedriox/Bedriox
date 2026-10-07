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
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;

/** Adult-only pursuit and melee behavior restricted to the authoritative anger target. */
final readonly class PolarBearAttackGoal implements AiGoal
{
    public function identifier(): string
    {
        return 'bedriox:polar_bear_attack';
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
        if ($entity instanceof PolarBearEntity) {
            $entity->setStanding(false);
        }
        HorizontalSteering::stop($entity, $context->tick);
    }

    private function pursueOrAttack(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): void {
        $target = $this->target($entity, $memory, $context);
        if ($target === null) {
            if ($entity instanceof PolarBearEntity) {
                $entity->setStanding(false);
            }
            HorizontalSteering::stop($entity, $context->tick);

            return;
        }

        $distanceSquared = $target->distanceSquaredTo($entity->internalPosition());
        if ($entity instanceof PolarBearEntity) {
            $entity->setStanding($distanceSquared <= 4.0 ** 2);
        }
        if ($distanceSquared > 2.0 ** 2) {
            HorizontalSteering::toward($entity, $target->position, 0.16, $context->tick, $context->world);

            return;
        }

        HorizontalSteering::stop($entity, $context->tick);
        if ($memory->contains(VanillaAiMemories::meleeCooldown(), $context->tick)) {
            return;
        }
        $memory->put(
            VanillaAiMemories::meleeIntent(),
            new AiMeleeIntent($target->playerId, $context->tick, 2.0, 6.0),
            $context->tick + 2,
        );
        $memory->put(VanillaAiMemories::meleeCooldown(), true, $context->tick + 20);
    }

    private function target(
        AbstractMobEntity $entity,
        AiMemoryStore $memory,
        AiTickContext $context,
    ): ?AiPlayerSnapshot {
        if (!$entity instanceof PolarBearEntity || !$entity->isAlive() || $entity->isBaby()
            || $entity->getRemainingAngerTicks() < 1) {
            return null;
        }
        $target = $memory->get(VanillaAiMemories::nearestPlayer(), $context->tick);

        return $target instanceof AiPlayerSnapshot
            && $target->playerId === $entity->getAngerTargetUniqueId()
            ? $target
            : null;
    }
}
