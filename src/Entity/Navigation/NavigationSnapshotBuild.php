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

namespace Bedriox\Server\Entity\Navigation;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\LoadedCollisionBoxQuery;
use Bedriox\Server\World\Collision\LoadedNavigationCellQuery;

/** @internal Resumable main-thread capture of loaded collision state for one navigation request. */
final class NavigationSnapshotBuild
{
    /** @var list<NavigationPoint> */
    private array $walkable = [];
    private int $cursor = 0;
    private bool $unavailable = false;

    public function __construct(
        public readonly int $runtimeId,
        public readonly string $identity,
        public readonly string $world,
        public readonly Position $origin,
        public readonly Position $target,
        public readonly string $terrainRevision,
        public readonly int $minimumX,
        public readonly int $minimumY,
        public readonly int $minimumZ,
        public readonly int $sizeX,
        public readonly int $sizeY,
        public readonly int $sizeZ,
        public readonly float $entityWidth,
        public readonly float $entityHeight,
    ) {}

    public function advance(LoadedCollisionBoxQuery $collisions): void
    {
        if ($this->isComplete()) {
            return;
        }
        $layerSize = $this->sizeX * $this->sizeZ;
        $localY = intdiv($this->cursor, $layerSize);
        $inLayer = $this->cursor % $layerSize;
        $localZ = intdiv($inLayer, $this->sizeX);
        $localX = $inLayer % $this->sizeX;
        $x = $this->minimumX + $localX;
        $y = $this->minimumY + $localY;
        $z = $this->minimumZ + $localZ;
        if ($collisions instanceof LoadedNavigationCellQuery) {
            $walkable = $collisions->isNavigationCellWalkableLoaded(
                $x,
                $y,
                $z,
                $this->entityWidth,
                $this->entityHeight,
            );
        } else {
            $halfWidth = $this->entityWidth / 2.0;
            $body = new AxisAlignedBox(
                $x + 0.5 - $halfWidth,
                $y,
                $z + 0.5 - $halfWidth,
                $x + 0.5 + $halfWidth,
                $y + $this->entityHeight,
                $z + 0.5 + $halfWidth,
            );
            $bodyBlocks = $collisions->boxesIntersectingLoaded($body);
            $support = $collisions->boxesIntersectingLoaded($body->offset(0.0, -0.1, 0.0));
            $walkable = $bodyBlocks === null || $support === null
                ? null
                : $bodyBlocks === [] && $support !== [];
        }
        if ($walkable === null) {
            $this->unavailable = true;
            $this->cursor = $this->cellCount();
            return;
        }
        if ($walkable) {
            $this->walkable[] = new NavigationPoint($x, $y, $z);
        }
        ++$this->cursor;
    }

    public function isComplete(): bool
    {
        return $this->cursor >= $this->cellCount();
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }

    public function cellsScanned(): int
    {
        return $this->cursor;
    }

    /** @return list<NavigationPoint> */
    public function walkablePoints(): array
    {
        return $this->walkable;
    }

    private function cellCount(): int
    {
        return $this->sizeX * $this->sizeY * $this->sizeZ;
    }
}
