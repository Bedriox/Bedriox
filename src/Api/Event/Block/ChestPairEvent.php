<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Block;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;

/** Runs after chest-pair validation and before the authoritative pair is created. */
final class ChestPairEvent extends CancellableEvent
{
    public function __construct(
        public readonly Block $left,
        public readonly Block $right,
        public readonly ?Player $player = null,
    ) {}
}
