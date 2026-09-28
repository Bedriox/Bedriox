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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\Ai\AiClock;
use Bedriox\Server\Entity\Ai\AiScheduler;
use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorkBudget;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\SystemAiClock;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Closure;
use InvalidArgumentException;

/** Authoritative mutable owner for general entities; called only by simulation. */
final class EntityWorldRuntime
{
    /** @var array<int, true> */
    private array $reportedDeaths = [];

    private ?AiSchedulerMetrics $lastAiMetrics = null;

    private ?EntityRuntimeMetrics $lastRuntimeMetrics = null;

    private int $physicsCursor = 0;

    /** @var null|Closure(AbstractMobEntity, int): void */
    private readonly ?Closure $scheduledAiTick;

    public function __construct(
        private readonly EntityRegistry $entities,
        private readonly EntitySpawnService $spawns,
        private readonly ?EntityPhysicsResolver $physics = null,
        private readonly AiScheduler $ai = new AiScheduler(),
        ?Closure $scheduledAiTick = null,
        private readonly AiClock $clock = new SystemAiClock(),
    ) {
        $this->scheduledAiTick = $scheduledAiTick;
    }

    public function registry(): EntityRegistry
    {
        return $this->entities;
    }

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        return $this->spawns->spawn($request);
    }

    public function damage(int $runtimeId, float $amount): ?EntityDamageResult
    {
        $entity = $this->entities->getByRuntimeId($runtimeId);
        if (!$entity instanceof AbstractLivingEntity || !$entity->isAlive()) {
            return null;
        }
        $applied = $entity->damage($amount);

        return new EntityDamageResult($entity, $applied, $entity->getHealth() <= 0.0);
    }

    public function remove(int $runtimeId): ?AbstractEntity
    {
        unset($this->reportedDeaths[$runtimeId]);

        return $this->entities->remove($runtimeId);
    }

    public function tick(
        int $tick,
        AiWorldView $world,
        bool $aiEnabled,
        AiWorkBudget $budget = new AiWorkBudget(),
        EntityWorkBudget $entityBudget = new EntityWorkBudget(),
    ): EntityRuntimeTick {
        if ($tick < 0) {
            throw new InvalidArgumentException('Entity runtime tick cannot be negative.');
        }
        $mobs = [];
        foreach ($this->entities->all() as $entity) {
            if ($entity instanceof AbstractMobEntity && $entity->isAlive()) {
                $mobs[] = $entity;
            }
        }
        $metrics = $this->lastAiMetrics = $this->ai->tick(
            $mobs,
            new AiTickContext($tick, $world),
            $aiEnabled,
            $budget,
            $this->scheduledAiTick === null
                ? null
                : function (AbstractMobEntity $entity, AiTickContext $context): void {
                    ($this->scheduledAiTick)($entity, $context->tick);
                },
        );

        $moved = [];
        $movedMotionChanged = [];
        $died = [];
        $entities = $this->entities->all();
        foreach ($entities as $entity) {
            if ($entity instanceof AbstractLivingEntity && !$entity->isAlive()) {
                if (!isset($this->reportedDeaths[$entity->getRuntimeId()])) {
                    $this->reportedDeaths[$entity->getRuntimeId()] = true;
                    $died[] = $entity;
                }
            }
        }

        $start = $this->clock->nanoseconds();
        $entityCount = count($entities);
        $physicsEligible = $physicsTicked = $cadenceSkipped = $budgetDeferred = 0;
        $continuousBeyondBudget = $motionChanged = 0;
        $budgetExhausted = false;
        if ($entityCount > 0) {
            $startIndex = $this->physicsCursor % $entityCount;
            for ($offset = 0; $offset < $entityCount; ++$offset) {
                $entity = $entities[($startIndex + $offset) % $entityCount];
                if ($entity instanceof AbstractLivingEntity && !$entity->isAlive()) {
                    continue;
                }
                if ($this->physics === null) {
                    $entity->advanceAge();
                    continue;
                }
                $continuous = self::requiresContinuousPhysics($entity);
                if (!$continuous && !self::physicsCadenceDue($entity, $tick)) {
                    $entity->advanceAge();
                    ++$cadenceSkipped;
                    continue;
                }
                ++$physicsEligible;
                $withinEntityBudget = $physicsTicked < $entityBudget->maximumPhysicsEntities;
                $withinTimeBudget = ($physicsTicked % 16) !== 0
                    || $this->clock->nanoseconds() - $start < $entityBudget->maximumNanoseconds;
                if ((!$withinEntityBudget || !$withinTimeBudget) && !$continuous) {
                    $entity->advanceAge();
                    ++$budgetDeferred;
                    $budgetExhausted = true;
                    continue;
                }
                if ((!$withinEntityBudget || !$withinTimeBudget) && $continuous) {
                    ++$continuousBeyondBudget;
                    $budgetExhausted = true;
                }
                $result = $this->physics->tick($entity, $tick);
                ++$physicsTicked;
                $motionChanged += $result->motionChanged ? 1 : 0;
                if ($result->moved) {
                    $this->entities->reindex($entity);
                    $moved[] = $entity;
                    $movedMotionChanged[$entity->getRuntimeId()] = $result->motionChanged;
                }
            }
            $this->physicsCursor = ($startIndex + max(1, $physicsTicked)) % $entityCount;
        }

        $elapsed = max(0, $this->clock->nanoseconds() - $start);
        $runtime = $this->lastRuntimeMetrics = new EntityRuntimeMetrics(
            $entityCount,
            $physicsEligible,
            $physicsTicked,
            $cadenceSkipped,
            $budgetDeferred,
            $continuousBeyondBudget,
            count($moved),
            $motionChanged,
            $elapsed,
            $budgetExhausted,
        );

        return new EntityRuntimeTick($moved, $died, $metrics, $runtime, $movedMotionChanged);
    }

    public function lastAiMetrics(): ?AiSchedulerMetrics
    {
        return $this->lastAiMetrics;
    }

    public function lastRuntimeMetrics(): ?EntityRuntimeMetrics
    {
        return $this->lastRuntimeMetrics;
    }

    private static function requiresContinuousPhysics(AbstractEntity $entity): bool
    {
        $motion = $entity->getMotion();

        return !$entity->isOnGround()
            || $motion->x !== 0.0
            || $motion->y !== 0.0
            || $motion->z !== 0.0;
    }

    private static function physicsCadenceDue(AbstractEntity $entity, int $tick): bool
    {
        if (!$entity instanceof AbstractMobEntity) {
            return true;
        }

        $cadence = match ($entity->getActivationState()) {
            MobActivationState::ACTIVE, MobActivationState::FORCED => 1,
            MobActivationState::REDUCED => 5,
            MobActivationState::SLEEPING => 20,
        };

        return ($tick + $entity->getRuntimeId()) % $cadence === 0;
    }
}
