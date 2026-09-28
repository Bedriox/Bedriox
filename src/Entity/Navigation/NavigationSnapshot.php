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

use InvalidArgumentException;

/** Immutable compact walkability volume safe to transfer to a path worker. */
final readonly class NavigationSnapshot
{
    public const int MAXIMUM_CELLS = 262_144;

    private int $cellCount;

    public function __construct(
        public int $minimumX,
        public int $minimumY,
        public int $minimumZ,
        public int $sizeX,
        public int $sizeY,
        public int $sizeZ,
        private string $walkableBits,
        public string $revision,
    ) {
        if ($sizeX < 1 || $sizeX > 128 || $sizeY < 1 || $sizeY > 64 || $sizeZ < 1 || $sizeZ > 128) {
            throw new InvalidArgumentException('Navigation snapshot dimensions are outside their supported bounds.');
        }
        $this->cellCount = $sizeX * $sizeY * $sizeZ;
        if ($this->cellCount > self::MAXIMUM_CELLS
            || strlen($walkableBits) !== intdiv($this->cellCount + 7, 8)) {
            throw new InvalidArgumentException('Navigation snapshot bitset does not match its bounded dimensions.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new InvalidArgumentException('Navigation snapshot revision must be one SHA-256 digest.');
        }
        new NavigationPoint($minimumX, $minimumY, $minimumZ);
        new NavigationPoint($minimumX + $sizeX - 1, $minimumY + $sizeY - 1, $minimumZ + $sizeZ - 1);
    }

    /** @param array<array-key, mixed> $walkable */
    public static function fromWalkablePoints(
        int $minimumX,
        int $minimumY,
        int $minimumZ,
        int $sizeX,
        int $sizeY,
        int $sizeZ,
        array $walkable,
        string $revision,
    ): self {
        $cells = $sizeX * $sizeY * $sizeZ;
        if ($cells < 1 || $cells > self::MAXIMUM_CELLS) {
            throw new InvalidArgumentException('Navigation snapshot cell count is outside its supported bounds.');
        }
        $bits = str_repeat("\0", intdiv($cells + 7, 8));
        $seen = [];
        foreach ($walkable as $point) {
            if (!$point instanceof NavigationPoint) {
                throw new InvalidArgumentException('Navigation snapshot contains an invalid walkable point.');
            }
            $localX = $point->x - $minimumX;
            $localY = $point->y - $minimumY;
            $localZ = $point->z - $minimumZ;
            if ($localX < 0 || $localX >= $sizeX || $localY < 0 || $localY >= $sizeY
                || $localZ < 0 || $localZ >= $sizeZ) {
                throw new InvalidArgumentException('Walkable point lies outside its navigation snapshot.');
            }
            $index = (($localY * $sizeZ) + $localZ) * $sizeX + $localX;
            if (isset($seen[$index])) {
                throw new InvalidArgumentException('Navigation snapshot contains a duplicate walkable point.');
            }
            $seen[$index] = true;
            $byte = intdiv($index, 8);
            $bits[$byte] = chr(ord($bits[$byte]) | (1 << ($index % 8)));
        }

        return new self(
            $minimumX,
            $minimumY,
            $minimumZ,
            $sizeX,
            $sizeY,
            $sizeZ,
            $bits,
            $revision,
        );
    }

    public function contains(NavigationPoint $point): bool
    {
        return $point->x >= $this->minimumX && $point->x < $this->minimumX + $this->sizeX
            && $point->y >= $this->minimumY && $point->y < $this->minimumY + $this->sizeY
            && $point->z >= $this->minimumZ && $point->z < $this->minimumZ + $this->sizeZ;
    }

    public function isWalkable(NavigationPoint $point): bool
    {
        if (!$this->contains($point)) {
            return false;
        }
        $index = $this->index($point);

        return (ord($this->walkableBits[intdiv($index, 8)]) & (1 << ($index % 8))) !== 0;
    }

    public function bits(): string
    {
        return $this->walkableBits;
    }

    private function index(NavigationPoint $point): int
    {
        $localX = $point->x - $this->minimumX;
        $localY = $point->y - $this->minimumY;
        $localZ = $point->z - $this->minimumZ;

        return (($localY * $this->sizeZ) + $localZ) * $this->sizeX + $localX;
    }
}
