<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

final class BlockPlaceEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
    ) {}
}
