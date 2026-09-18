<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

final readonly class LoginReadyEffect implements LoginEffect
{
    public function __construct(public AuthenticatedLogin $login) {}
}
