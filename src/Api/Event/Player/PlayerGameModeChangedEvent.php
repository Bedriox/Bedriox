<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;

/** Immutable notification emitted after an authoritative player mode change. */
final class PlayerGameModeChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly GameMode $previous,
        public readonly GameMode $gameMode,
    ) {}
}
