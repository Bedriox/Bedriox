<?php

declare(strict_types=1);

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Server\Player\PlayerBootstrap;

interface PlayerDataStore
{
    public function exists(string $uuid): bool;

    public function load(string $uuid): ?PlayerBootstrap;

    public function save(PlayerBootstrap $player): void;
}
