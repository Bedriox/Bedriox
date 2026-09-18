<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\LoginChannelReady;

interface PlayChannelFactory
{
    public function create(LoginChannelReady $ready, string $sessionId, UnsignedLong $runtimeEntityId): BedrockPlayChannel;
}
