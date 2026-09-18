<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;

/** Transfers authenticated identity and the live directional cipher state to the post-login owner. */
final readonly class LoginChannelReady
{
    public function __construct(
        public AuthenticatedLogin $login,
        public BedrockEncryptor $encryptor,
        public BedrockDecryptor $decryptor,
        public int $protocolVersion,
    ) {}
}
