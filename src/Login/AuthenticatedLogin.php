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
