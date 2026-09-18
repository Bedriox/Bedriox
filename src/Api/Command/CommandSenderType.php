<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

enum CommandSenderType
{
    case CONSOLE;
    case PLAYER;
}
