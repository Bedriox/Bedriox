<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

final readonly class LoginSecurityPosture
{
    public const string SELF_SIGNED_NOTICE = 'SELF_SIGNED login authentication is insecure and must not be used for public servers.';

    public function __construct(public AuthenticationMode $mode) {}

    public function requiresOperatorWarning(): bool
    {
        return $this->mode === AuthenticationMode::SELF_SIGNED;
    }

    public function operatorNotice(): ?string
    {
        return $this->requiresOperatorWarning() ? self::SELF_SIGNED_NOTICE : null;
    }
}
