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

use Bedriox\Api\Entity\Capability\Sittable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiControl;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use InvalidArgumentException;

final readonly class FollowOwnerGoal implements AiGoal
{
    public function __construct(
        private string $identifier,
        private int $priority,
        private float $startDistance,
        private float $stopDistance,
        private float $speed,
    ) {
        if ($identifier === '' || strlen($identifier) > 128
            || !is_finite($startDistance) || !is_finite($stopDistance)
            || $startDistance <= $stopDistance || $startDistance > 32.0 || $stopDistance < 1.0
            || !is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Owner-follow goal bounds are invalid.');
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
        return 5;
    }

    public function controls(): array
    {
        return [AiControl::MOVE, AiControl::LOOK];
    }

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $owner = $this->owner($entity, $context);

        return $owner !== null
            && $owner->distanceSquaredTo($entity->internalPosition()) > $this->startDistance ** 2;
    }

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool
    {
        $owner = $this->owner($entity, $context);

        return $owner !== null
            && $owner->distanceSquaredTo($entity->internalPosition()) > $this->stopDistance ** 2;
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
        $owner = $this->owner($entity, $context);
        if ($owner !== null) {
            HorizontalSteering::toward($entity, $owner->position, $this->speed, $context->tick, $context->world);
        }
    }

    private function owner(AbstractMobEntity $entity, AiTickContext $context): ?AiPlayerSnapshot
    {
        if (!$entity instanceof Tameable || !$entity->isTamed()
            || ($entity instanceof Sittable && $entity->isSitting())
            || !$context->world instanceof PlayerIdentityAiWorldView) {
            return null;
        }
        $ownerId = $entity->getOwnerUniqueId();
        if ($ownerId === null) {
            return null;
        }
        $owner = $context->world->playerByIdentity($ownerId);

        return $owner !== null && $owner->worldName === $entity->getWorldName() ? $owner : null;
    }
}
