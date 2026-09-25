<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

/** Published after the authoritative chest pair is created. */
final class ChestPairedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Block $left,
        public readonly Block $right,
        public readonly ?Player $player = null,
    ) {}
}
