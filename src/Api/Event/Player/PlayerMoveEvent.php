<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;

final class PlayerMoveEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Position $from,
        public readonly Position $to,
    ) {}
}
