<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Simulation\Event\EntityActorMoved;

/** Projects one authoritative entity movement update into packets shared by every visible recipient. */
interface EntityMovementPacketEncoder
{
    /** @return non-empty-list<Packet> */
    public function entityMovementPackets(EntityActorMoved $event): array;
}
