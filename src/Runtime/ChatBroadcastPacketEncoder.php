<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Simulation\Event\ChatBroadcast;

/** @internal Projects one authoritative chat packet for shared multi-recipient delivery. */
interface ChatBroadcastPacketEncoder
{
    public function chatPacket(ChatBroadcast $event): Packet;
}
