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

namespace Bedriox\Server\World\Generation\Geology;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;

/** Replays independently-seeded named geology rules with one-chunk placement halo. */
final class OverworldGeologyGenerator
{
    /** @var list<OverworldGeologyRule> */
    private readonly array $rules;
    /** @var array<int, array<int, InternalBlockStateId>> */
    private readonly array $replacementsByRule;
    private readonly int $air;
    private readonly SeededNoise $noise;

    public function __construct(
        private readonly int $worldSeed,
        GenerationBlockPalette $palette,
    ) {
        $this->rules = OverworldGeologyCatalog::rules();
        $states = [];
        foreach ($this->rules as $ruleIndex => $rule) {
            foreach ($rule->replacements as $host => $replacement) {
                $states[$host] ??= $palette->state($host);
                $states[$replacement] ??= $palette->state($replacement);
            }
        }
        $replacementsByRule = [];
        foreach ($this->rules as $ruleIndex => $rule) {
            $replacements = [];
            foreach ($rule->replacements as $host => $replacement) {
                $replacements[$states[$host]->value] = $states[$replacement];
            }
            $replacementsByRule[$ruleIndex] = $replacements;
        }
        $this->replacementsByRule = $replacementsByRule;
        $this->air = $palette->state('minecraft:air')->value;
        $this->noise = new SeededNoise($worldSeed);
    }

    public function populate(MutableChunkBuilder $builder, ChunkPosition $target): void
    {
        $targetOriginX = $target->x * 16;
        $targetOriginZ = $target->z * 16;
        // Deposits are chunk-owned. Keeping placement local avoids replaying the complete
        // geology catalogue nine times while remaining deterministic at chunk boundaries.
        for ($sourceChunkZ = $target->z; $sourceChunkZ <= $target->z; ++$sourceChunkZ) {
            for ($sourceChunkX = $target->x; $sourceChunkX <= $target->x; ++$sourceChunkX) {
                foreach ($this->rules as $ruleIndex => $rule) {
                    $salt = 11_003 + $ruleIndex * 977;
                    $attempts = $rule->chanceBound !== null
                        ? ($this->noise->chance($sourceChunkX, $ruleIndex, $sourceChunkZ, $salt, $rule->chanceBound) === 0 ? 1 : 0)
                        : $rule->attempts;
                    for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                        $attemptSalt = $salt + $attempt * 17;
                        $centerX = $sourceChunkX * 16
                            + $this->noise->chance($sourceChunkX, $attempt, $sourceChunkZ, $attemptSalt, 16);
                        $centerZ = $sourceChunkZ * 16
                            + $this->noise->chance($sourceChunkX, $attempt, $sourceChunkZ, $attemptSalt + 1, 16);
                        $firstHeight = $this->noise->chance(
                            $sourceChunkX,
                            $attempt,
                            $sourceChunkZ,
                            $attemptSalt + 2,
                            $rule->heightBound,
                        );
                        $secondHeight = $rule->triangularHeight
                            ? $this->noise->chance($sourceChunkX, $attempt, $sourceChunkZ, $attemptSalt + 3, $rule->heightBound)
                            : 0;
                        $centerY = $rule->heightOffset + $firstHeight + $secondHeight;
                        $radius = max(1.0, ($rule->veinSize ** (1.0 / 3.0)) * 0.62);
                        if ($centerX + $radius < $targetOriginX || $centerX - $radius > $targetOriginX + 15
                            || $centerZ + $radius < $targetOriginZ || $centerZ - $radius > $targetOriginZ + 15) {
                            continue;
                        }
                        $replacements = $this->replacementsByRule[$ruleIndex];
                        $centerLocalX = $centerX - $targetOriginX;
                        $centerLocalZ = $centerZ - $targetOriginZ;
                        if (!isset($replacements[$builder->state($centerLocalX, $centerY, $centerLocalZ)->value])) {
                            continue;
                        }
                        for ($worldZ = max($targetOriginZ, (int) floor($centerZ - $radius));
                            $worldZ <= min($targetOriginZ + 15, (int) ceil($centerZ + $radius)); ++$worldZ) {
                            $normalizedZ = ($worldZ + 0.5 - $centerZ) / $radius;
                            for ($worldX = max($targetOriginX, (int) floor($centerX - $radius));
                                $worldX <= min($targetOriginX + 15, (int) ceil($centerX + $radius)); ++$worldX) {
                                $normalizedX = ($worldX + 0.5 - $centerX) / $radius;
                                $horizontal = $normalizedX * $normalizedX + $normalizedZ * $normalizedZ;
                                if ($horizontal >= 1.0) {
                                    continue;
                                }
                                for ($y = max(-64, (int) floor($centerY - $radius));
                                    $y <= min(319, (int) ceil($centerY + $radius)); ++$y) {
                                    $normalizedY = ($y + 0.5 - $centerY) / $radius;
                                    if ($horizontal + $normalizedY * $normalizedY >= 1.0) {
                                        continue;
                                    }
                                    $localX = $worldX - $targetOriginX;
                                    $localZ = $worldZ - $targetOriginZ;
                                    $replacement = $replacements[$builder->state($localX, $y, $localZ)->value] ?? null;
                                    if (!$replacement instanceof InternalBlockStateId) {
                                        continue;
                                    }
                                    if ($rule->discardChanceOnAirExposure > 0.0
                                        && $this->touchesAir($builder, $localX, $y, $localZ)
                                        && $this->noise->chance($worldX, $y, $worldZ, $attemptSalt + 7, 1_000)
                                            < (int) ($rule->discardChanceOnAirExposure * 1_000)) {
                                        continue;
                                    }
                                    $builder->set($localX, $y, $localZ, $replacement);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function touchesAir(MutableChunkBuilder $builder, int $x, int $y, int $z): bool
    {
        return ($x < 15 && $builder->state($x + 1, $y, $z)->value === $this->air)
            || ($x > 0 && $builder->state($x - 1, $y, $z)->value === $this->air)
            || $builder->state($x, $y + 1, $z)->value === $this->air
            || $builder->state($x, $y - 1, $z)->value === $this->air
            || ($z < 15 && $builder->state($x, $y, $z + 1)->value === $this->air)
            || ($z > 0 && $builder->state($x, $y, $z - 1)->value === $this->air);
    }
}
