<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\World;

/** Queries the current authoritative world. All admitted MVP solid states are full cubes. */
final readonly class BlockCollisionQuery implements CollisionBoxQuery
{
    /** @var array<int, true> */
    private array $nonSolid;

    /** @param list<InternalBlockStateId> $additionalNonSolid */
    public function __construct(
        private World $world,
        private InternalBlockStateId $air,
        array $additionalNonSolid = [],
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
        $boxes = [];
        $minY = max(Chunk::MIN_Y, (int) floor($area->minY));
        $maxY = min(Chunk::MAX_Y, (int) floor($area->maxY));
        if ($minY > $maxY) {
            return [];
        }
        for ($z = (int) floor($area->minZ); $z <= (int) floor($area->maxZ); ++$z) {
            for ($x = (int) floor($area->minX); $x <= (int) floor($area->maxX); ++$x) {
                for ($y = $minY; $y <= $maxY; ++$y) {
                    if (isset($this->nonSolid[$this->world->blockStateAt($x, $y, $z)->value])) {
                        continue;
                    }
                    $box = AxisAlignedBox::unitAt($x, $y, $z);
                    if ($box->intersects($area)) {
                        $boxes[] = $box;
                    }
                }
            }
        }

        return $boxes;
    }

    public function hasCollision(AxisAlignedBox $area): bool
    {
        return $this->boxesIntersecting($area) !== [];
    }
}
