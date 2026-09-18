<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;

interface PlayInitializationFactory
{
    /** @return list<Packet> */
    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId): array;

    /** @return array{air: int, bedrock: int, dirt: int, grass_block: int} */
    public function fixedFlatRuntimeIds(): array;
}
