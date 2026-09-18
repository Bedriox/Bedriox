<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

final class BlockBrokenEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
    ) {}
}
