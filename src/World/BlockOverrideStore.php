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

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;
use OverflowException;

/** Bounded process-local block changes grouped by chunk for deterministic regeneration. */
final class BlockOverrideStore
{
    /** @var array<string, array<string, array{x: int, y: int, z: int, state: InternalBlockStateId}>> */
    private array $byChunk = [];

    private int $count = 0;

    public function __construct(private readonly int $maximumOverrides = 100_000)
    {
        if ($maximumOverrides < 1 || $maximumOverrides > 10_000_000) {
            throw new InvalidArgumentException('Block override capacity is outside its supported range.');
        }
    }

    public function set(
        ChunkPosition $chunk,
        int $localX,
        int $y,
        int $localZ,
        InternalBlockStateId $state,
    ): void {
        $cell = self::cellKey($localX, $y, $localZ);
        $existing = isset($this->byChunk[$chunk->key()][$cell]);
        if (!$existing && $this->count >= $this->maximumOverrides) {
            throw new OverflowException('Block override capacity is exhausted.');
        }
        $this->byChunk[$chunk->key()][$cell] = [
            'x' => $localX,
            'y' => $y,
            'z' => $localZ,
            'state' => $state,
        ];
        if (!$existing) {
            ++$this->count;
        }
    }

    /** @return list<array{x: int, y: int, z: int, state: InternalBlockStateId}> */
    public function forChunk(ChunkPosition $chunk): array
    {
        return array_values($this->byChunk[$chunk->key()] ?? []);
    }

    public function count(): int
    {
        return $this->count;
    }

    private static function cellKey(int $x, int $y, int $z): string
    {
        if ($x < 0 || $x >= 16 || $z < 0 || $z >= 16 || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            throw new InvalidArgumentException('Block override coordinate is outside its chunk.');
        }

        return $x . ':' . $y . ':' . $z;
    }
}
