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

namespace Bedriox\Server\Gameplay\Explosion\Planning;

use Bedriox\Server\Gameplay\Explosion\Value\ExplosionPlan;
use Bedriox\Server\Gameplay\Explosion\Value\ExplosionRequest;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Chunk;
use InvalidArgumentException;

/** Deterministic, read-only, bounded block-impact planning for authoritative explosions. */
final readonly class ExplosionPlanningService
{
    private const int RAY_GRID_SIZE = 16;
    private const float RAY_STEP = 0.3;
    private const float STEP_ENERGY_COST = 0.225;
    private const int MAXIMUM_RAY_STEPS = 128;

    public function __construct(private int $maximumAffectedBlocks = 4_096)
    {
        if ($maximumAffectedBlocks < 1 || $maximumAffectedBlocks > 4_096) {
            throw new InvalidArgumentException('Explosion affected-block limit must be between 1 and 4096.');
        }
    }

    public function plan(ExplosionRequest $request, ExplosionWorldView $world): ExplosionPlan
    {
        if (!$request->breaksBlocks) {
            return new ExplosionPlan([], [], false);
        }

        /** @var array<string, BlockPosition> $affected */
        $affected = [];
        $truncated = false;
        $edge = self::RAY_GRID_SIZE - 1;
        for ($ix = 0; $ix <= $edge && !$truncated; ++$ix) {
            for ($iy = 0; $iy <= $edge && !$truncated; ++$iy) {
                for ($iz = 0; $iz <= $edge; ++$iz) {
                    if ($ix !== 0 && $ix !== $edge && $iy !== 0 && $iy !== $edge && $iz !== 0 && $iz !== $edge) {
                        continue;
                    }
                    $dx = ($ix / $edge) * 2.0 - 1.0;
                    $dy = ($iy / $edge) * 2.0 - 1.0;
                    $dz = ($iz / $edge) * 2.0 - 1.0;
                    $length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
                    $dx /= $length;
                    $dy /= $length;
                    $dz /= $length;

                    $energy = $request->radius * 1.3;
                    $distance = 0.0;
                    for ($step = 0; $step < self::MAXIMUM_RAY_STEPS && $energy > 0.0; ++$step) {
                        $x = $request->center->x + $dx * $distance;
                        $y = $request->center->y + $dy * $distance;
                        $z = $request->center->z + $dz * $distance;
                        if ($x < -30_000_000.0 || $x > 30_000_000.0 || $z < -30_000_000.0 || $z > 30_000_000.0
                            || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
                            break;
                        }
                        $position = new BlockPosition((int) floor($x), (int) floor($y), (int) floor($z));
                        $sample = $world->sample($position);
                        if ($sample === null) {
                            break;
                        }
                        if (!$sample->air) {
                            $energy -= ($sample->blastResistance + 0.3) * self::RAY_STEP;
                            if ($energy > 0.0) {
                                $key = self::key($position);
                                $affected[$key] = $position;
                                if (count($affected) >= $this->maximumAffectedBlocks) {
                                    $truncated = true;
                                    break;
                                }
                            }
                        }
                        $energy -= self::STEP_ENERGY_COST;
                        $distance += self::RAY_STEP;
                    }
                }
            }
        }

        $affected = self::sorted(array_values($affected));
        $ignitions = $request->fireChance > 0.0
            ? $this->ignitionCandidates($affected, $world)
            : [];

        return new ExplosionPlan($affected, $ignitions, $truncated);
    }

    /**
     * @param list<BlockPosition> $affected
     * @return list<BlockPosition>
     */
    private function ignitionCandidates(array $affected, ExplosionWorldView $world): array
    {
        /** @var array<string, BlockPosition> $candidates */
        $candidates = [];
        foreach ($affected as $position) {
            if ($position->y >= Chunk::MAX_Y) {
                continue;
            }
            $candidate = new BlockPosition($position->x, $position->y + 1, $position->z);
            $sample = $world->sample($candidate);
            if ($sample?->air !== true) {
                continue;
            }
            $candidates[self::key($candidate)] = $candidate;
            if (count($candidates) >= $this->maximumAffectedBlocks) {
                break;
            }
        }

        return self::sorted(array_values($candidates));
    }

    private static function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }

    /**
     * @param list<BlockPosition> $positions
     * @return list<BlockPosition>
     */
    private static function sorted(array $positions): array
    {
        usort($positions, static fn(BlockPosition $left, BlockPosition $right): int => [
            $left->x,
            $left->y,
            $left->z,
        ] <=> [
            $right->x,
            $right->y,
            $right->z,
        ]);

        return $positions;
    }
}
