<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Simulation\Event\PlayerMoved;

/** Projects one authoritative movement update into packets shared by every recipient. */
interface PlayerMovementPacketEncoder
{
    /** @return non-empty-list<Packet> */
    public function playerMovementPackets(PlayerMoved $event): array;
}
