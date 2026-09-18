<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Security\P384KeyPair;
use InvalidArgumentException;

final readonly class HandshakeMaterial
{
    public function __construct(public P384KeyPair $keyPair, public string $salt)
    {
        if (strlen($salt) !== 16) {
            throw new InvalidArgumentException('Handshake salt must contain exactly 16 bytes.');
        }
    }
}
