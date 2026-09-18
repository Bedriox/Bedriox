<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Authentication\FullTokenAuthenticator;
use Bedriox\Server\Authentication\SubjectIdentityUuid;

/** Connects the server's FULL token verifier to the transport-independent login machine. */
final readonly class FullLoginAuthenticatorAdapter implements LoginAuthenticator
{
    public function __construct(private FullTokenAuthenticator $authenticator) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::FULL) {
            throw new AuthenticationException('FULL authentication adapter cannot authenticate another mode.');
        }
        $identity = $this->authenticator->authenticate($packet->authentication, $packet->clientJwt);
        return new AuthenticatedLogin(
            $identity->displayName,
            SubjectIdentityUuid::fromSubject($identity->subject),
            $identity->xuid,
            $identity->clientPublicKey,
            $identity->clientData,
        );
    }
}
