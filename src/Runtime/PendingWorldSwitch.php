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

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\DimensionId;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\World;

/** Holds destination terrain leases until a live session crosses its ordered output boundary. */
final class PendingWorldSwitch
{
    /** @var array<string, ChunkPosition> */
    private array $retained = [];

    /** @var array<string, true> */
    private array $projectionReady = [];

    /** @var list<ChunkPosition> */
    private array $required;

    private bool $adopted = false;

    public readonly ChunkViewManager $view;

    public function __construct(
        public readonly World $world,
        public readonly ?PreparedChunkCache $preparedChunks,
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly int $worldTime,
        public readonly int $difficulty,
        public readonly ?DimensionId $dimension,
        int $radius,
        int $prefetchRadius,
        int $prewarmRadius,
    ) {
        $this->view = new ChunkViewManager($radius, $prefetchRadius);
        $this->view->centerOnBlock($x, $z);
        $centerX = (int) floor($x / 16.0);
        $centerZ = (int) floor($z / 16.0);
        $required = [];
        for ($chunkX = $centerX - $prewarmRadius; $chunkX <= $centerX + $prewarmRadius; ++$chunkX) {
            for ($chunkZ = $centerZ - $prewarmRadius; $chunkZ <= $centerZ + $prewarmRadius; ++$chunkZ) {
                $required[] = new ChunkPosition($chunkX, $chunkZ);
            }
        }
        usort($required, static function (ChunkPosition $left, ChunkPosition $right) use ($centerX, $centerZ): int {
            $leftDistance = ($left->x - $centerX) ** 2 + ($left->z - $centerZ) ** 2;
            $rightDistance = ($right->x - $centerX) ** 2 + ($right->z - $centerZ) ** 2;

            return $leftDistance <=> $rightDistance
                ?: $left->x <=> $right->x
                ?: $left->z <=> $right->z;
        });
        $this->required = $required;
    }

    /** @return list<ChunkPosition> */
    public function required(): array
    {
        return $this->required;
    }

    public function isRetained(ChunkPosition $position): bool
    {
        return isset($this->retained[$position->key()]);
    }

    public function retain(ChunkPosition $position): void
    {
        $this->retained[$position->key()] = $position;
        $this->view->markPrepared($position->x, $position->z);
    }

    public function markProjectionReady(ChunkPosition $position): void
    {
        $this->projectionReady[$position->key()] = true;
    }

    public function isReady(): bool
    {
        return count($this->retained) === count($this->required)
            && count($this->projectionReady) === count($this->required);
    }

    /** @return array<string, ChunkPosition> */
    public function adoptRetained(): array
    {
        $this->adopted = true;

        return $this->retained;
    }

    public function release(): void
    {
        if ($this->adopted) {
            return;
        }
        foreach ($this->retained as $position) {
            $this->world->releaseChunk($position);
        }
        $this->retained = [];
        $this->projectionReady = [];
    }
}
