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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use InvalidArgumentException;

final readonly class NaturalSpawnLimits
{
    /** @var array<string, NaturalSpawnCategoryLimit> */
    private array $categories;

    /** @param array<int, NaturalSpawnCategoryLimit> $categories */
    public function __construct(
        public int $candidateRadius,
        public int $maximumAttempts,
        public int $maximumSpawns,
        public int $maximumElapsedNanoseconds,
        public float $minimumPlayerDistance,
        public float $minimumWorldSpawnDistance,
        array $categories,
    ) {
        if ($candidateRadius < 0 || $candidateRadius > 7
            || $maximumAttempts < 1 || $maximumAttempts > 4_096
            || $maximumSpawns < 1 || $maximumSpawns > 256 || $maximumSpawns > $maximumAttempts
            || $maximumElapsedNanoseconds < 1 || $maximumElapsedNanoseconds > 50_000_000
            || !is_finite($minimumPlayerDistance) || $minimumPlayerDistance < 0.0
            || $minimumPlayerDistance > 1_024.0
            || !is_finite($minimumWorldSpawnDistance) || $minimumWorldSpawnDistance < 0.0
            || $minimumWorldSpawnDistance > 1_024.0
            || !array_is_list($categories) || $categories === []) {
            throw new InvalidArgumentException('Natural-spawn limits are invalid.');
        }
        $indexed = [];
        foreach ($categories as $limit) {
            if (isset($indexed[$limit->category->value])) {
                throw new InvalidArgumentException('Natural-spawn category limit is duplicated.');
            }
            $indexed[$limit->category->value] = $limit;
        }
        $this->categories = $indexed;
    }

    public function category(EntityCategory $category): ?NaturalSpawnCategoryLimit
    {
        return $this->categories[$category->value] ?? null;
    }

    public function effectiveWorldCap(EntityCategory $category, int $candidateCount): ?int
    {
        if ($candidateCount < 0 || $candidateCount > NaturalSpawnCandidatePlanner::MAXIMUM_CANDIDATES) {
            throw new InvalidArgumentException('Natural-spawn candidate count is outside its supported bounds.');
        }
        $limit = $this->category($category);
        if ($limit === null) {
            return null;
        }
        if ($limit->worldCap === 0 || $candidateCount === 0) {
            return 0;
        }
        $referenceRegionArea = (($this->candidateRadius * 2) + 1) ** 2;

        return min(
            1_000_000,
            max(1, intdiv(($limit->worldCap * $candidateCount) + $referenceRegionArea - 1, $referenceRegionArea)),
        );
    }
}
