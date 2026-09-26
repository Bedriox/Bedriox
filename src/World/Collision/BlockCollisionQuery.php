<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\World;

/** Resolves state-owned collision geometry against the current authoritative world. */
final readonly class BlockCollisionQuery implements LoadedCollisionBoxQuery
{
    /** @var array<int, true> */
    private array $nonSolid;

    /** @param list<InternalBlockStateId> $additionalNonSolid */
    public function __construct(
        private World $world,
        private InternalBlockStateId $air,
        array $additionalNonSolid = [],
        private ?BlockCollisionRegistry $shapes = null,
    ) {
        $nonSolid = [$air->value => true];
        foreach ($additionalNonSolid as $state) {
            $nonSolid[$state->value] = true;
        }
        $this->nonSolid = $nonSolid;
    }

    /** @return list<AxisAlignedBox> */
    public function boxesIntersecting(AxisAlignedBox $area): array
    {
        return $this->collectBoxes($area, false) ?? [];
    }

    public function boxesIntersectingLoaded(AxisAlignedBox $area): ?array
    {
        return $this->collectBoxes($area, true);
    }

    public function hasCollision(AxisAlignedBox $area): bool
    {
        return $this->boxesIntersecting($area) !== [];
    }

    /** Returns false for occluded paths and whenever any traversed terrain chunk is not already loaded. */
    public function hasLoadedLineOfSight(Position $from, Position $to): bool
    {
        $deltaX = $to->x - $from->x;
        $deltaY = $to->y - $from->y;
        $deltaZ = $to->z - $from->z;
        $x = (int) floor($from->x);
        $y = (int) floor($from->y);
        $z = (int) floor($from->z);
        $endX = (int) floor($to->x);
        $endY = (int) floor($to->y);
        $endZ = (int) floor($to->z);
        $maximumCells = abs($endX - $x) + abs($endY - $y) + abs($endZ - $z) + 1;
        if ($maximumCells > 1_024) {
            return false;
        }

        $stepX = $deltaX <=> 0.0;
        $stepY = $deltaY <=> 0.0;
        $stepZ = $deltaZ <=> 0.0;
        $tDeltaX = $stepX === 0 ? INF : abs(1.0 / $deltaX);
        $tDeltaY = $stepY === 0 ? INF : abs(1.0 / $deltaY);
        $tDeltaZ = $stepZ === 0 ? INF : abs(1.0 / $deltaZ);
        $tMaxX = self::firstBoundaryTime($from->x, $x, $deltaX, $stepX);
        $tMaxY = self::firstBoundaryTime($from->y, $y, $deltaY, $stepY);
        $tMaxZ = self::firstBoundaryTime($from->z, $z, $deltaZ, $stepZ);

        for ($visited = 0; $visited < $maximumCells; ++$visited) {
            $chunk = $this->world->loadedChunk(new ChunkPosition(
                (int) floor($x / 16),
                (int) floor($z / 16),
            ));
            if (!$chunk instanceof Chunk) {
                return false;
            }
            if ($y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y) {
                $state = $chunk->blockStateAt(self::localCoordinate($x), $y, self::localCoordinate($z));
                foreach ($this->collisionBoxes($state, $x, $y, $z) as $box) {
                    if (self::segmentIntersects($from, $deltaX, $deltaY, $deltaZ, $box)) {
                        return false;
                    }
                }
            }
            if ($x === $endX && $y === $endY && $z === $endZ) {
                return true;
            }

            $next = min($tMaxX, $tMaxY, $tMaxZ);
            if ($tMaxX <= $next + 1.0e-12) {
                $x += $stepX;
                $tMaxX += $tDeltaX;
            }
            if ($tMaxY <= $next + 1.0e-12) {
                $y += $stepY;
                $tMaxY += $tDeltaY;
            }
            if ($tMaxZ <= $next + 1.0e-12) {
                $z += $stepZ;
                $tMaxZ += $tDeltaZ;
            }
        }

        return false;
    }

    /** @return list<AxisAlignedBox>|null */
    private function collectBoxes(AxisAlignedBox $area, bool $loadedOnly): ?array
    {
        $boxes = [];
        $minY = max(Chunk::MIN_Y, (int) floor($area->minY));
        $maxY = min(Chunk::MAX_Y, self::maximumCell($area->minY, $area->maxY));
        if ($minY > $maxY) {
            return [];
        }
        $minX = (int) floor($area->minX);
        $maxX = self::maximumCell($area->minX, $area->maxX);
        $minZ = (int) floor($area->minZ);
        $maxZ = self::maximumCell($area->minZ, $area->maxZ);
        if ($loadedOnly) {
            for ($chunkZ = (int) floor($minZ / 16); $chunkZ <= (int) floor($maxZ / 16); ++$chunkZ) {
                for ($chunkX = (int) floor($minX / 16); $chunkX <= (int) floor($maxX / 16); ++$chunkX) {
                    if (!$this->world->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ))) {
                        return null;
                    }
                }
            }
        }
        for ($z = $minZ; $z <= $maxZ; ++$z) {
            for ($x = $minX; $x <= $maxX; ++$x) {
                $chunk = $loadedOnly
                    ? $this->world->loadedChunk(new ChunkPosition((int) floor($x / 16), (int) floor($z / 16)))
                    : null;
                if ($loadedOnly && !$chunk instanceof Chunk) {
                    return null;
                }
                for ($y = $minY; $y <= $maxY; ++$y) {
                    $state = $chunk instanceof Chunk
                        ? $chunk->blockStateAt(self::localCoordinate($x), $y, self::localCoordinate($z))
                        : $this->world->blockStateAt($x, $y, $z);
                    foreach ($this->collisionBoxes($state, $x, $y, $z) as $box) {
                        if ($box->intersects($area)) {
                            $boxes[] = $box;
                        }
                    }
                }
            }
        }

        return $boxes;
    }

    /** @return list<AxisAlignedBox> */
    private function collisionBoxes(InternalBlockStateId $state, int $x, int $y, int $z): array
    {
        $shape = $this->shapes?->find($state);
        if ($shape?->isEmpty() === true || ($shape === null && isset($this->nonSolid[$state->value]))) {
            return [];
        }

        return $shape?->boxesAt($x, $y, $z) ?? [AxisAlignedBox::unitAt($x, $y, $z)];
    }

    private static function firstBoundaryTime(float $origin, int $cell, float $delta, int $step): float
    {
        if ($step === 0) {
            return INF;
        }
        $boundary = $step > 0 ? $cell + 1.0 : (float) $cell;

        return ($boundary - $origin) / $delta;
    }

    private static function segmentIntersects(
        Position $origin,
        float $deltaX,
        float $deltaY,
        float $deltaZ,
        AxisAlignedBox $box,
    ): bool {
        $minimumTime = 0.0;
        $maximumTime = 1.0;
        foreach ([
            [$origin->x, $deltaX, $box->minX, $box->maxX],
            [$origin->y, $deltaY, $box->minY, $box->maxY],
            [$origin->z, $deltaZ, $box->minZ, $box->maxZ],
        ] as [$coordinate, $delta, $minimum, $maximum]) {
            if (abs($delta) < 1.0e-12) {
                if ($coordinate < $minimum || $coordinate > $maximum) {
                    return false;
                }
                continue;
            }
            $near = ($minimum - $coordinate) / $delta;
            $far = ($maximum - $coordinate) / $delta;
            if ($near > $far) {
                [$near, $far] = [$far, $near];
            }
            $minimumTime = max($minimumTime, $near);
            $maximumTime = min($maximumTime, $far);
            if ($minimumTime > $maximumTime) {
                return false;
            }
        }

        return $maximumTime >= 0.0 && $minimumTime <= 1.0;
    }

    private static function maximumCell(float $minimum, float $maximum): int
    {
        return max((int) floor($minimum), (int) ceil($maximum) - 1);
    }

    private static function localCoordinate(int $coordinate): int
    {
        return (($coordinate % 16) + 16) % 16;
    }
}
