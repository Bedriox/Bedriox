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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;

final class AiBehaviorRuntime
{
    private readonly AiMemoryStore $memory;

    /** @var array<int, true> Goal definition index. */
    private array $runningGoals = [];

    public function __construct(private readonly AiBehaviorDefinition $definition)
    {
        $this->memory = new AiMemoryStore();
    }

    public function memory(): AiMemoryStore
    {
        return $this->memory;
    }

    public function tick(AbstractMobEntity $entity, AiTickContext $context): AiTickResult
    {
        $sensorsRun = 0;
        foreach ($this->definition->sensors as $index => $sensor) {
            if (($context->tick + $entity->getRuntimeId() + $index) % $sensor->intervalTicks() !== 0) {
                continue;
            }
            $sensor->sense($entity, $this->memory, $context);
            ++$sensorsRun;
        }

        $startedThisTick = [];
        foreach (array_keys($this->runningGoals) as $index) {
            $goal = $this->definition->goals[$index];
            if (!$goal->shouldContinue($entity, $this->memory, $context)) {
                $goal->stop($entity, $this->memory, $context);
                unset($this->runningGoals[$index]);
            }
        }

        $evaluated = 0;
        foreach ($this->definition->goals as $index => $goal) {
            if (isset($this->runningGoals[$index])
                || ($context->tick + $entity->getRuntimeId() + $index) % $goal->evaluationIntervalTicks() !== 0) {
                continue;
            }
            ++$evaluated;
            if (!$goal->canStart($entity, $this->memory, $context)) {
                continue;
            }
            $conflicts = $this->conflictingRunningGoals($goal);
            if ($this->hasEqualOrHigherPriority($goal, $conflicts)) {
                continue;
            }
            foreach ($conflicts as $conflictingIndex) {
                $this->definition->goals[$conflictingIndex]->stop($entity, $this->memory, $context);
                unset($this->runningGoals[$conflictingIndex]);
            }
            $goal->start($entity, $this->memory, $context);
            $this->runningGoals[$index] = true;
            $startedThisTick[$index] = true;
        }

        $goalsTicked = 0;
        foreach (array_keys($this->runningGoals) as $index) {
            if (isset($startedThisTick[$index])) {
                continue;
            }
            $this->definition->goals[$index]->tick($entity, $this->memory, $context);
            ++$goalsTicked;
        }

        return new AiTickResult($sensorsRun, $evaluated, $goalsTicked);
    }

    public function stopAll(AbstractMobEntity $entity, AiTickContext $context): void
    {
        foreach (array_keys($this->runningGoals) as $index) {
            $this->definition->goals[$index]->stop($entity, $this->memory, $context);
        }
        $this->runningGoals = [];
    }

    public function takeMeleeIntent(int $tick): ?AiMeleeIntent
    {
        $intent = $this->memory->take(VanillaAiMemories::meleeIntent(), $tick);

        return $intent instanceof AiMeleeIntent ? $intent : null;
    }

    /** @return list<int> */
    private function conflictingRunningGoals(AiGoal $candidate): array
    {
        $conflicts = [];
        foreach (array_keys($this->runningGoals) as $index) {
            if (self::conflicts($candidate, $this->definition->goals[$index])) {
                $conflicts[] = $index;
            }
        }

        return $conflicts;
    }

    /** @param list<int> $conflicts */
    private function hasEqualOrHigherPriority(AiGoal $candidate, array $conflicts): bool
    {
        foreach ($conflicts as $index) {
            if ($this->definition->goals[$index]->priority() >= $candidate->priority()) {
                return true;
            }
        }

        return false;
    }

    private static function conflicts(AiGoal $left, AiGoal $right): bool
    {
        foreach ($left->controls() as $leftControl) {
            foreach ($right->controls() as $rightControl) {
                if ($leftControl === $rightControl) {
                    return true;
                }
            }
        }

        return false;
    }
}
