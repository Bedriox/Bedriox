<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerInteractionType;

final class PlayerAttackedEvent extends Event implements PostEvent
{
    public readonly PlayerInteractionType $interaction;

    public function __construct(
        public readonly Player $attacker,
        public readonly Player $target,
        public readonly float $damage,
    ) {
        $this->interaction = PlayerInteractionType::ATTACK;
    }
}
