<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\LoginPacket;

interface LoginAuthenticator
{
    /** Implementations must fail closed and must never fall back between modes. */
    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin;
}
