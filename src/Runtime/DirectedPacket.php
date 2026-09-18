<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use InvalidArgumentException;

final readonly class DirectedPacket
{
    public function __construct(public string $sessionId, public Packet $packet)
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('Directed packet session ID cannot be empty.');
        }
    }
}
