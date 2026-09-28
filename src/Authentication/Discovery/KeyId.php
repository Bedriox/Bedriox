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

namespace Bedriox\Server\Authentication\Discovery;

use InvalidArgumentException;

final readonly class KeyId
{
    public function __construct(public string $value)
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Key identifier is invalid.');
        }
    }
}
