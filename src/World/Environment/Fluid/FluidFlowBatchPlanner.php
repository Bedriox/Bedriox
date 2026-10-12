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

namespace Bedriox\Server\World\Environment\Fluid;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Plans one deterministic fluid frontier against an unchanged loaded-world snapshot. */
final readonly class FluidFlowBatchPlanner
{
    private const ADJACENT_EXCEPT_DOWN = [[0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    public function __construct(private FluidFlowPlanner $planner) {}

    /** @param list<BlockPosition> $origins */
    public function plan(FluidWorldView $world, array $origins): FluidFlowBatch
    {
        $unique = [];
        foreach ($origins as $origin) {
            $unique[$this->key($origin)] = $origin;
        }
        ksort($unique, SORT_STRING);

        $mutations = [];
        $retries = [];
        $deferredRetries = [];
        $deferred = [];
        $visited = 0;
        foreach ($unique as $key => $origin) {
            $cell = $world->cellAt($origin);
            $plan = $cell?->fluid === null
                ? $this->planOpenedCell($world, $origin)
                : $this->planner->plan($world, $origin);
            $visited += $plan->visitedCells;
            if ($cell?->fluid !== null || $plan->deferredPositions !== []) {
                $retries[$key] = $origin;
            }
            if ($plan->deferredPositions !== []) {
                $deferredRetries[$key] = $origin;
            }
            foreach ($plan->deferredPositions as $position) {
                $deferred[$this->key($position)] = $position;
            }
            foreach ($plan->mutations as $mutation) {
                $this->admitMutation($mutations, $mutation);
            }
        }

        $this->admitImmediateReactions($world, $mutations);
        ksort($mutations, SORT_STRING);

        return new FluidFlowBatch(
            array_values($mutations),
            array_values($retries),
            array_values($deferredRetries),
            array_values($deferred),
            $visited,
        );
    }

    private function planOpenedCell(FluidWorldView $world, BlockPosition $opening): FluidFlowPlan
    {
        $cell = $world->cellAt($opening);
        if ($cell === null) {
            return new FluidFlowPlan([], [$opening], 1);
        }
        if (!$cell->replaceable || $cell->fluid !== null) {
            return new FluidFlowPlan([], [], 1);
        }

        $above = $this->offset($opening, 0, 1, 0);
        $aboveFluid = $above === null ? null : $world->cellAt($above)?->fluid;
        if ($aboveFluid !== null) {
            return new FluidFlowPlan([
                new FluidMutation(
                    FluidMutationType::SET_FLUID,
                    $opening,
                    FluidState::falling($aboveFluid->type)->canonicalState(),
                ),
            ], [], 2);
        }

        $sourceTypes = [];
        $visited = 1;
        foreach ([[0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as [$x, $y, $z]) {
            $neighbor = $this->offset($opening, $x, $y, $z);
            if ($neighbor === null) {
                continue;
            }
            ++$visited;
            $fluid = $world->cellAt($neighbor)?->fluid;
            if ($fluid?->isSource() === true && $fluid->type->formsSources()) {
                $sourceTypes[$fluid->type->value] = ($sourceTypes[$fluid->type->value] ?? 0) + 1;
            }
        }
        foreach ($sourceTypes as $type => $count) {
            if ($count >= 2) {
                return new FluidFlowPlan([
                    new FluidMutation(
                        FluidMutationType::SET_FLUID,
                        $opening,
                        FluidState::source(FluidType::from($type))->canonicalState(),
                    ),
                ], [], $visited);
            }
        }

        return new FluidFlowPlan([], [], $visited);
    }

    /** @param array<string, FluidMutation> $mutations */
    private function admitMutation(array &$mutations, FluidMutation $candidate): void
    {
        $key = $this->key($candidate->position);
        $existing = $mutations[$key] ?? null;
        if ($existing === null || $this->compare($candidate, $existing) > 0) {
            $mutations[$key] = $candidate;
        }
    }

    /** @param array<string, FluidMutation> $mutations */
    private function admitImmediateReactions(FluidWorldView $world, array &$mutations): void
    {
        foreach (array_values($mutations) as $mutation) {
            $fluid = FluidState::fromCanonical($mutation->state);
            if ($mutation->type !== FluidMutationType::SET_FLUID || $fluid === null) {
                continue;
            }

            if ($fluid->type === FluidType::LAVA && $world->cellAt($mutation->position)?->fluid?->type === FluidType::WATER) {
                $this->admitMutation($mutations, new FluidMutation(
                    FluidMutationType::FORM_BLOCK,
                    $mutation->position,
                    CanonicalBlockState::from('minecraft:stone'),
                ));
                continue;
            }

            foreach (self::ADJACENT_EXCEPT_DOWN as [$x, $y, $z]) {
                $neighbor = $this->offset($mutation->position, $x, $y, $z);
                if ($neighbor === null) {
                    continue;
                }
                $neighborFluid = $world->cellAt($neighbor)?->fluid;
                if ($neighborFluid === null || $neighborFluid->type === $fluid->type) {
                    continue;
                }
                if ($fluid->type === FluidType::LAVA) {
                    $result = $fluid->isSource() ? 'minecraft:obsidian' : ($fluid->depth <= 4 ? 'minecraft:cobblestone' : null);
                    if ($result !== null) {
                        $this->admitMutation($mutations, new FluidMutation(
                            FluidMutationType::FORM_BLOCK,
                            $mutation->position,
                            CanonicalBlockState::from($result),
                        ));
                    }
                    continue;
                }
                if (!$neighborFluid->falling) {
                    $result = $neighborFluid->isSource()
                        ? 'minecraft:obsidian'
                        : ($neighborFluid->depth <= 4 ? 'minecraft:cobblestone' : null);
                    if ($result !== null) {
                        $this->admitMutation($mutations, new FluidMutation(
                            FluidMutationType::FORM_BLOCK,
                            $neighbor,
                            CanonicalBlockState::from($result),
                        ));
                    }
                }
            }
        }
    }

    private function compare(FluidMutation $left, FluidMutation $right): int
    {
        $type = $this->priority($left->type) <=> $this->priority($right->type);
        if ($type !== 0 || $left->type !== FluidMutationType::SET_FLUID) {
            return $type;
        }
        $leftFluid = FluidState::fromCanonical($left->state);
        $rightFluid = FluidState::fromCanonical($right->state);
        if ($leftFluid === null || $rightFluid === null) {
            return $left->state->canonicalKey() <=> $right->state->canonicalKey();
        }
        if ($leftFluid->type !== $rightFluid->type) {
            return $rightFluid->type->value <=> $leftFluid->type->value;
        }
        $leftStrength = ($leftFluid->falling ? 8 : 16) - $leftFluid->depth;
        $rightStrength = ($rightFluid->falling ? 8 : 16) - $rightFluid->depth;

        return $leftStrength <=> $rightStrength;
    }

    private function priority(FluidMutationType $type): int
    {
        return match ($type) {
            FluidMutationType::FORM_BLOCK => 3,
            FluidMutationType::SET_FLUID => 2,
            FluidMutationType::REMOVE_FLUID => 1,
        };
    }

    private function offset(BlockPosition $position, int $x, int $y, int $z): ?BlockPosition
    {
        try {
            return new BlockPosition($position->x + $x, $position->y + $y, $position->z + $z);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
