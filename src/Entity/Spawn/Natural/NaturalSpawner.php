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
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use RuntimeException;

/** Deterministic side-effect-free natural-spawn request planner with bounded fair cursors. */
final class NaturalSpawner
{
    /** @var list<NaturalSpawnEntry> */
    private array $entries;

    private int $entryCursor = 0;
    private int $candidateCursor = 0;
    private int $totalWeight = 0;

    /** @param array<int, NaturalSpawnEntry> $entries */
    public function __construct(
        private readonly NaturalSpawnCandidatePlanner $candidatePlanner,
        private readonly NaturalSpawnEnvironment $environment,
        private readonly NaturalSpawnClock $clock,
        private readonly NaturalSpawnLimits $limits,
        array $entries,
    ) {
        if (!array_is_list($entries) || count($entries) > 512) {
            throw new InvalidArgumentException('Natural-spawn entries are unordered or oversized.');
        }
        $identifiers = [];
        foreach ($entries as $entry) {
            $identifier = $entry->type->identifier();
            if (isset($identifiers[$identifier]) || $limits->category($entry->category) === null) {
                throw new InvalidArgumentException('Natural-spawn entry is duplicated or lacks category limits.');
            }
            $identifiers[$identifier] = true;
            $this->totalWeight += $entry->weight;
            if ($this->totalWeight > 100_000) {
                throw new InvalidArgumentException('Natural-spawn entry weights are oversized.');
            }
        }
        $this->entries = $entries;
    }

    /** @param array<int, NaturalSpawnPlayer> $players */
    public function plan(
        string $worldName,
        array $players,
        int $worldSeed,
        int $tick,
    ): NaturalSpawnBatch {
        if ($this->entries === []) {
            return new NaturalSpawnBatch([], 0, 0, false, false);
        }
        $start = $this->clock->nowNanoseconds();
        if ($start < 0) {
            throw new RuntimeException('Natural-spawn clock returned an invalid time.');
        }
        $candidates = $this->candidatePlanner->plan(
            $worldName,
            $players,
            $this->limits->candidateRadius,
            $worldSeed,
            $tick,
        );
        if ($candidates === []) {
            return new NaturalSpawnBatch([], 0, 0, false, false);
        }
        $afterPreparation = $this->clock->nowNanoseconds();
        if ($afterPreparation < $start) {
            throw new RuntimeException('Natural-spawn clock moved backwards.');
        }
        if ($afterPreparation - $start >= $this->limits->maximumElapsedNanoseconds) {
            return new NaturalSpawnBatch([], 0, count($candidates), true, false);
        }
        $requests = [];
        $attempts = 0;
        $timeExhausted = false;
        $plannedByCategory = [];
        $plannedByLocalCategory = [];
        $candidateCount = count($candidates);
        $availableAttempts = min($this->limits->maximumAttempts, $candidateCount);

        while ($attempts < $availableAttempts && count($requests) < $this->limits->maximumSpawns) {
            $now = $this->clock->nowNanoseconds();
            if ($now < $start) {
                throw new RuntimeException('Natural-spawn clock moved backwards.');
            }
            if ($now - $start >= $this->limits->maximumElapsedNanoseconds) {
                $timeExhausted = true;
                break;
            }
            $chunkIndex = ($this->candidateCursor + $attempts) % $candidateCount;
            $chunk = $candidates[$chunkIndex];
            $entry = $this->nextEntry();
            ++$attempts;

            if (!$this->environment->isChunkLoaded($worldName, $chunk)
                || !$this->environment->isChunkStable($worldName, $chunk)) {
                continue;
            }
            $limit = $this->limits->category($entry->category);
            if ($limit === null) {
                throw new RuntimeException('Natural-spawn entry category has no limits.');
            }
            $worldPlanned = $plannedByCategory[$entry->category->value] ?? 0;
            $effectiveWorldCap = $this->limits->effectiveWorldCap($entry->category, $candidateCount)
                ?? throw new RuntimeException('Natural-spawn entry category has no effective limit.');
            if ($this->boundedCount($this->environment->categoryCount($worldName, $entry->category))
                    + $worldPlanned >= $effectiveWorldCap) {
                continue;
            }
            $localKey = $chunk->key() . ':' . $entry->category->value;
            $localPlanned = $plannedByLocalCategory[$localKey] ?? 0;
            if ($this->boundedCount($this->environment->localCategoryDensity($worldName, $chunk, $entry->category))
                    + $localPlanned >= $limit->localDensityCap) {
                continue;
            }
            $position = $this->candidatePosition(
                $worldName,
                $entry->candidateMedium,
                $worldSeed,
                $tick,
                $chunk,
                $attempts,
            );
            if ($position === null) {
                continue;
            }
            $playerDistance = $this->environment->nearestPlayerDistanceSquared($worldName, $position);
            $worldSpawnDistance = $this->environment->worldSpawnDistanceSquared($worldName, $position);
            if ($playerDistance === null || !is_finite($playerDistance) || $playerDistance < 0.0
                || !is_finite($worldSpawnDistance) || $worldSpawnDistance < 0.0
                || $playerDistance < $this->limits->minimumPlayerDistance ** 2
                || $worldSpawnDistance < $this->limits->minimumWorldSpawnDistance ** 2
                || !$this->environment->isCollisionFree($worldName, $entry->type, $position)) {
                continue;
            }
            $context = new NaturalSpawnContext(
                $worldName,
                $chunk,
                $entry->type,
                $entry->category,
                $position,
                $this->environment->dimension($worldName),
                $this->environment->biome($worldName, $position),
                $this->environment->medium($worldName, $position),
                $this->environment->lightLevel($worldName, $position),
                $playerDistance,
                $worldSpawnDistance,
                $this->environment instanceof WorldNaturalSpawnEnvironment
                    ? $this->environment->supportBlock($worldName, $position)
                    : null,
                $this->environment->netherStructure($worldName, $position),
            );
            if (!$entry->rule->allows($context)) {
                continue;
            }

            $yaw = self::unit($worldSeed, $tick, $chunk, $attempts, 'yaw') * 360.0;
            $requests[] = new EntitySpawnRequest(
                $entry->type,
                SpawnCause::NATURAL,
                $worldName,
                $position,
                $yaw,
                variant: $entry->type === VanillaEntityType::MAGMA_CUBE
                    ? MagmaCubeSpawnVariantSelector::select($worldSeed, $position, $tick)->value
                    : null,
            );
            $plannedByCategory[$entry->category->value] = $worldPlanned + 1;
            $plannedByLocalCategory[$localKey] = $localPlanned + 1;
        }
        $this->candidateCursor = ($this->candidateCursor + $attempts) % $candidateCount;

        return new NaturalSpawnBatch(
            $requests,
            $attempts,
            $candidateCount,
            $timeExhausted,
            $attempts >= $this->limits->maximumAttempts && $attempts < $candidateCount,
        );
    }

    public function effectiveCategoryCap(EntityCategory $category, int $candidateCount): ?int
    {
        return $this->limits->effectiveWorldCap($category, $candidateCount);
    }

    private function nextEntry(): NaturalSpawnEntry
    {
        $selection = $this->entryCursor % $this->totalWeight;
        $this->entryCursor = ($this->entryCursor + 1) % $this->totalWeight;
        foreach ($this->entries as $entry) {
            if ($selection < $entry->weight) {
                return $entry;
            }
            $selection -= $entry->weight;
        }

        throw new RuntimeException('Natural-spawn weighted cursor is invalid.');
    }

    private function candidatePosition(
        string $worldName,
        NaturalSpawnMedium $medium,
        int $worldSeed,
        int $tick,
        ChunkPosition $chunk,
        int $attempt,
    ): ?Position {
        $x = ($chunk->x * 16) + (int) floor(self::unit($worldSeed, $tick, $chunk, $attempt, 'x') * 16.0) + 0.5;
        $z = ($chunk->z * 16) + (int) floor(self::unit($worldSeed, $tick, $chunk, $attempt, 'z') * 16.0) + 0.5;
        $position = $this->environment->candidatePosition(
            $worldName,
            $medium,
            $x,
            $z,
            self::unit($worldSeed, $tick, $chunk, $attempt, 'y'),
        );
        if ($position !== null && (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || $position->y < -2_048.0 || $position->y > 2_048.0)) {
            throw new RuntimeException('Natural-spawn environment returned an invalid candidate position.');
        }

        return $position;
    }

    private function boundedCount(int $count): int
    {
        if ($count < 0 || $count > 1_000_000) {
            throw new RuntimeException('Natural-spawn environment returned an invalid entity count.');
        }

        return $count;
    }

    private static function unit(
        int $worldSeed,
        int $tick,
        ChunkPosition $chunk,
        int $attempt,
        string $axis,
    ): float {
        $bytes = hash(
            'sha256',
            $worldSeed . ':' . $tick . ':' . $chunk->x . ':' . $chunk->z . ':' . $attempt . ':' . $axis,
            true,
        );
        $value = unpack('Nvalue', substr($bytes, 0, 4))['value'] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Natural-spawn deterministic value cannot be decoded.');
        }

        return $value / 4_294_967_296.0;
    }
}
