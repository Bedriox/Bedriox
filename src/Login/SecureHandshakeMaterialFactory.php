<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Security\EphemeralKeyFactory;

final readonly class SecureHandshakeMaterialFactory implements HandshakeMaterialFactory
{
    public function __construct(private EphemeralKeyFactory $keys) {}

    public function create(): HandshakeMaterial
    {
        return new HandshakeMaterial($this->keys->generate(), random_bytes(16));
    }
}
