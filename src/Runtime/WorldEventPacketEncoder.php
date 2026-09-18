<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Simulation\Event\WorldEvent;

interface WorldEventPacketEncoder
{
    /**
     * @param array<string, RuntimeSession> $sessions
     * @return list<DirectedPacket>
     */
    public function encode(WorldEvent $event, array $sessions): array;
}
