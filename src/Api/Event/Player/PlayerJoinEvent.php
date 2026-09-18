<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;

final class PlayerJoinEvent extends Event implements PostEvent
{
    public function __construct(public readonly Player $player) {}
}
