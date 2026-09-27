<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationException;

/** Admits verified retail identities and explicit self-signed clients in development mode. */
final readonly class DevelopmentLoginAuthenticator implements LoginAuthenticator
{
    public function __construct(
        private LoginAuthenticator $full,
        private LoginAuthenticator $selfSigned,
    ) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::SELF_SIGNED) {
            throw new AuthenticationException('Development authenticator cannot authenticate another mode.');
        }

        return match ($packet->authentication->type) {
            AuthenticationType::Full => $this->full->authenticate($packet, AuthenticationMode::FULL),
            AuthenticationType::SelfSigned => $this->selfSigned->authenticate($packet, AuthenticationMode::SELF_SIGNED),
            AuthenticationType::Guest => throw new AuthenticationException('Guest authentication is not supported.'),
        };
    }
}
