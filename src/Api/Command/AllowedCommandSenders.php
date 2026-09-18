<?php

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
