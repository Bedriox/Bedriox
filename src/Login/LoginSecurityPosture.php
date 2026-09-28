<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
