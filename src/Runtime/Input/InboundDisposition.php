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

namespace Bedriox\Server\Runtime\Input;

enum InboundDisposition
{
    case HANDLED;
    case IGNORED;
    case CORRECTED;
    case REJECTED;
    case SEMANTIC_VIOLATION;
    case PROTOCOL_VIOLATION;
    case SESSION_FATAL;

    public function requiresDisconnect(): bool
    {
        return $this === self::PROTOCOL_VIOLATION || $this === self::SESSION_FATAL;
    }
}
