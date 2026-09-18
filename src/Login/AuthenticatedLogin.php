<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Security\P384;
use OpenSSLAsymmetricKey;

final readonly class AuthenticatedLogin
{
    public function __construct(
        public string $displayName,
        public string $identity,
        public string $xuid,
        public OpenSSLAsymmetricKey $identityPublicKey,
        public VerifiedClientData $clientData,
    ) {
        P384::assertPublicKey($identityPublicKey);
    }
}
