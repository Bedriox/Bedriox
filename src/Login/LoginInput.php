<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\Packet;
use InvalidArgumentException;

final readonly class LoginInput
{
    public function __construct(public Packet $packet, public int $wireBytes, public bool $encrypted)
    {
        if ($wireBytes < 1) {
            throw new InvalidArgumentException('Login input wire length must be positive.');
        }
    }
}
