<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

use Bedriox\Protocol\Identity\VerifiedClientData;
use OpenSSLAsymmetricKey;

final readonly class AuthenticatedIdentity
{
    public function __construct(
        public string $subject,
        public string $displayName,
        public string $xuid,
        public ?string $minecraftId,
        public OpenSSLAsymmetricKey $clientPublicKey,
        public VerifiedClientData $clientData,
    ) {}
}
