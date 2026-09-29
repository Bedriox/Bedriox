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

/** Produces an immutable one-tick fluid plan without loading chunks or mutating world state. */
final class FluidFlowPlanner
{
    private const HORIZONTAL = [[0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];
    private const ADJACENT_EXCEPT_DOWN = [[0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    private int $visited = 0;
    /** @var array<string, BlockPosition> */
    private array $deferred = [];
    /** @var array<string, null|FluidCell> */
    private array $cellCache = [];

    public function __construct(private readonly int $maximumVisitedCells = 256)
    {
        if ($maximumVisitedCells < 16 || $maximumVisitedCells > 4_096) {
            throw new InvalidArgumentException('Fluid planner cell budget must be between 16 and 4096.');
        }
    }

    public function plan(FluidWorldView $world, BlockPosition $origin): FluidFlowPlan
    {
        $this->visited = 0;
        $this->deferred = [];
        $this->cellCache = [];
        $originCell = $this->cell($world, $origin);
        $fluid = $originCell?->fluid;
        if ($fluid === null) {
            return $this->result([]);
        }

        $hardening = $this->hardeningMutation($world, $origin, $fluid);
        if ($hardening !== null) {
            return $this->result([$hardening]);
        }

        $mutations = [];
        $effective = $fluid;
        if (!$fluid->isSource()) {
            $next = $this->nextState($world, $origin, $fluid);
            if ($next === null) {
                $mutations[] = new FluidMutation(
                    FluidMutationType::REMOVE_FLUID,
                    $origin,
                    CanonicalBlockState::from('minecraft:air'),
                );

                return $this->result($mutations);
            }
            if ($next != $fluid) {
                $mutations[] = new FluidMutation(FluidMutationType::SET_FLUID, $origin, $next->canonicalState());
                $effective = $next;
            }
        }

        $below = $this->offset($origin, 0, -1, 0);
        $flowedDown = $below !== null && $this->canAccept($world, $below, $fluid->type);
        if ($flowedDown) {
            $falling = FluidState::falling($fluid->type);
            if ($this->shouldSet($world, $below, $falling)) {
                $mutations[] = new FluidMutation(
                    FluidMutationType::SET_FLUID,
                    $below,
                    $falling->canonicalState(),
                );
            }
        }

        $belowCell = $below === null ? null : $this->cell($world, $below);
        if ($effective->isSource() || !$flowedDown || ($belowCell !== null && !$belowCell->replaceable)) {
            $nextDepth = ($effective->falling ? 0 : $effective->depth) + $fluid->type->horizontalDecay();
            if ($nextDepth <= 7) {
                foreach ($this->optimalHorizontalTargets($world, $origin, $fluid->type) as $target) {
                    $flowing = new FluidState($fluid->type, $nextDepth, false);
                    if (!$this->shouldSet($world, $target, $flowing)) {
                        continue;
                    }
                    $mutations[] = new FluidMutation(
                        FluidMutationType::SET_FLUID,
                        $target,
                        $flowing->canonicalState(),
                    );
                }
            }
        }

        return $this->result($mutations);
    }

    private function nextState(FluidWorldView $world, BlockPosition $origin, FluidState $fluid): ?FluidState
    {
        $smallestDepth = null;
        $sources = 0;
        foreach (self::HORIZONTAL as [$dx, $dy, $dz]) {
            $position = $this->offset($origin, $dx, $dy, $dz);
            if ($position === null) {
                continue;
            }
            $adjacent = $this->cell($world, $position)?->fluid;
            if ($adjacent?->type !== $fluid->type) {
                continue;
            }
            if ($adjacent->isSource()) {
                ++$sources;
            }
            $depth = $adjacent->falling ? 0 : $adjacent->depth;
            $smallestDepth = $smallestDepth === null ? $depth : min($smallestDepth, $depth);
        }

        $above = $this->offset($origin, 0, 1, 0);
        $aboveFluid = $above === null ? null : $this->cell($world, $above)?->fluid;
        if ($aboveFluid?->type === $fluid->type) {
            return FluidState::falling($fluid->type);
        }

        if ($fluid->type->formsSources() && $sources >= 2) {
            $below = $this->offset($origin, 0, -1, 0);
            $support = $below === null ? null : $this->cell($world, $below);
            if ($support?->solidTop === true || ($support?->fluid?->type === $fluid->type && $support->fluid->isSource())) {
                return FluidState::source($fluid->type);
            }
        }

        if ($smallestDepth === null) {
            return null;
        }
        $nextDepth = $smallestDepth + $fluid->type->horizontalDecay();

        return $nextDepth <= 7 ? new FluidState($fluid->type, $nextDepth, false) : null;
    }

    private function hardeningMutation(
        FluidWorldView $world,
        BlockPosition $origin,
        FluidState $fluid,
    ): ?FluidMutation {
        if ($fluid->type !== FluidType::LAVA || $fluid->falling) {
            return null;
        }
        $below = $this->offset($origin, 0, -1, 0);
        if ($below !== null && $this->cell($world, $below)?->block->identifier() === 'minecraft:soul_soil') {
            foreach (self::HORIZONTAL as [$dx, $dy, $dz]) {
                $position = $this->offset($origin, $dx, $dy, $dz);
                if ($position !== null && $this->cell($world, $position)?->block->identifier() === 'minecraft:blue_ice') {
                    return new FluidMutation(
                        FluidMutationType::FORM_BLOCK,
                        $origin,
                        CanonicalBlockState::from('minecraft:basalt'),
                    );
                }
            }
        }
        foreach (self::ADJACENT_EXCEPT_DOWN as [$dx, $dy, $dz]) {
            $position = $this->offset($origin, $dx, $dy, $dz);
            if ($position !== null && $this->cell($world, $position)?->fluid?->type === FluidType::WATER) {
                $result = $fluid->isSource()
                    ? 'minecraft:obsidian'
                    : ($fluid->depth <= 4 ? 'minecraft:cobblestone' : null);
                if ($result === null) {
                    continue;
                }

                return new FluidMutation(
                    FluidMutationType::FORM_BLOCK,
                    $origin,
                    CanonicalBlockState::from($result),
                );
            }
        }

        return null;
    }

    /** @return list<BlockPosition> */
    private function optimalHorizontalTargets(
        FluidWorldView $world,
        BlockPosition $origin,
        FluidType $type,
    ): array {
        $costs = [];
        foreach (self::HORIZONTAL as $index => [$dx, $dy, $dz]) {
            $target = $this->offset($origin, $dx, $dy, $dz);
            if ($target === null || !$this->canAccept($world, $target, $type)) {
                continue;
            }
            $costs[$index] = $this->isDownwardOpening($world, $target, $type)
                ? 0
                : $this->minimumSlopeCost($world, $target, $type, $index ^ 1, $type->slopeDistance());
        }
        if ($costs === []) {
            return [];
        }

        $minimum = min($costs);
        $targets = [];
        foreach ($costs as $index => $cost) {
            if ($cost !== $minimum) {
                continue;
            }
            [$dx, $dy, $dz] = self::HORIZONTAL[$index];
            $target = $this->offset($origin, $dx, $dy, $dz);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function minimumSlopeCost(
        FluidWorldView $world,
        BlockPosition $start,
        FluidType $type,
        int $excludedDirection,
        int $maximumDistance,
    ): int {
        $queue = [[$start, $excludedDirection, 1]];
        $cursor = 0;
        $seen = [$this->key($start) => true];
        while (isset($queue[$cursor]) && $this->visited < $this->maximumVisitedCells) {
            [$position, $excluded, $distance] = $queue[$cursor++];
            foreach (self::HORIZONTAL as $index => [$dx, $dy, $dz]) {
                if ($index === $excluded) {
                    continue;
                }
                $target = $this->offset($position, $dx, $dy, $dz);
                if ($target === null || isset($seen[$this->key($target)]) || !$this->canAccept($world, $target, $type)) {
                    continue;
                }
                if ($this->isDownwardOpening($world, $target, $type)) {
                    return $distance;
                }
                if ($distance < $maximumDistance) {
                    $seen[$this->key($target)] = true;
                    $queue[] = [$target, $index ^ 1, $distance + 1];
                }
            }
        }

        return PHP_INT_MAX;
    }

    private function isDownwardOpening(FluidWorldView $world, BlockPosition $position, FluidType $type): bool
    {
        $below = $this->offset($position, 0, -1, 0);

        return $below !== null && $this->canAccept($world, $below, $type);
    }

    private function canAccept(FluidWorldView $world, BlockPosition $position, FluidType $type): bool
    {
        $cell = $this->cell($world, $position);
        if ($cell === null) {
            return false;
        }
        if ($cell->fluid !== null) {
            return $cell->fluid->type === $type && !$cell->fluid->isSource();
        }

        return $cell->replaceable;
    }

    private function shouldSet(FluidWorldView $world, BlockPosition $position, FluidState $candidate): bool
    {
        $cell = $this->cell($world, $position);
        if ($cell === null) {
            return false;
        }
        $existing = $cell->fluid;
        if ($existing === null) {
            return $cell->replaceable;
        }
        if ($existing->type !== $candidate->type || $existing->isSource() || $existing->falling) {
            return false;
        }

        return $candidate->falling || $candidate->depth < $existing->depth;
    }

    private function cell(FluidWorldView $world, BlockPosition $position): ?FluidCell
    {
        $key = $this->key($position);
        if (array_key_exists($key, $this->cellCache)) {
            return $this->cellCache[$key];
        }
        if ($this->visited >= $this->maximumVisitedCells) {
            return null;
        }
        ++$this->visited;
        $cell = $world->cellAt($position);
        $this->cellCache[$key] = $cell;
        if ($cell === null) {
            $this->deferred[$key] = $position;
        }

        return $cell;
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

    /** @param list<FluidMutation> $mutations */
    private function result(array $mutations): FluidFlowPlan
    {
        return new FluidFlowPlan($mutations, array_values($this->deferred), $this->visited);
    }
}
