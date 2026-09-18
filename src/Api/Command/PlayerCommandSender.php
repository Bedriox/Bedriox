<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

use Bedriox\Api\Player\Player;

interface PlayerCommandSender extends CommandSender
{
    public function player(): Player;
}
