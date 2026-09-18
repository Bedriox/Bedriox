<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\Packet;

final readonly class SendPacketEffect implements LoginEffect
{
    public function __construct(public Packet $packet, public bool $encrypted) {}
}
