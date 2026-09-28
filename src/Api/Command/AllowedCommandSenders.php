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

namespace Bedriox\Api\Command;

enum AllowedCommandSenders
{
    case ANY;
    case CONSOLE_ONLY;
    case PLAYER_ONLY;

    public function allows(CommandSenderType $type): bool
    {
        return $this === self::ANY
            || ($this === self::CONSOLE_ONLY && $type === CommandSenderType::CONSOLE)
            || ($this === self::PLAYER_ONLY && $type === CommandSenderType::PLAYER);
    }
}
