<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationException;
use Closure;

/** Defers the online key refresh until a development server receives a retail login. */
final class LazyFullLoginAuthenticator implements LoginAuthenticator
{
    private ?LoginAuthenticator $authenticator = null;

    /** @param Closure(): LoginAuthenticator $factory */
    public function __construct(private readonly Closure $factory) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::FULL) {
            throw new AuthenticationException('Lazy FULL authenticator cannot authenticate another mode.');
        }

        $this->authenticator ??= ($this->factory)();

        return $this->authenticator->authenticate($packet, $mode);
    }
}
