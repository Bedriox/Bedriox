<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use InvalidArgumentException;

final readonly class EnableEncryptionEffect implements LoginEffect
{
    public function __construct(public string $sessionKey)
    {
        if (strlen($sessionKey) !== 32) {
            throw new InvalidArgumentException('Session key must contain exactly 32 bytes.');
        }
    }
}
