<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

interface CollisionBoxQuery
{
    /** @return list<AxisAlignedBox> */
    public function boxesIntersecting(AxisAlignedBox $area): array;

    public function hasCollision(AxisAlignedBox $area): bool;
}
