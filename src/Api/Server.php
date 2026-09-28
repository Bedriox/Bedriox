<?php

declare(strict_types=1);

namespace Bedriox\Api;

use Bedriox\Api\Player\Player;
use Bedriox\Api\World\WorldManager;

interface Server
{
    public function getWorldManager(): WorldManager;

    /** @return list<Player> */
    public function getOnlinePlayers(): array;

    public function getPlayerByUuid(string $uuid): ?Player;

    public function getPlayerByName(string $name): ?Player;
}
