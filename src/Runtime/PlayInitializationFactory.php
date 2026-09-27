<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\PlayerBootstrap;

interface PlayInitializationFactory
{
    /** @return list<Packet|ReusablePlayPacket> */
    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): array;

    /** @return array{air: int, bedrock: int, dirt: int, grass_block: int} */
    public function fixedFlatRuntimeIds(): array;
}
