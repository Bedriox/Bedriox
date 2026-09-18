<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Login\BedrockLoginChannel;

interface LoginChannelFactory
{
    public function create(SessionInfo $session): BedrockLoginChannel;
}
