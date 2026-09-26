<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

/** Collision query which never loads terrain and fails when any required chunk is absent. */
interface LoadedCollisionBoxQuery extends CollisionBoxQuery
{
    /** @return list<AxisAlignedBox>|null Null means at least one intersected chunk is not loaded. */
    public function boxesIntersectingLoaded(AxisAlignedBox $area): ?array;
}
