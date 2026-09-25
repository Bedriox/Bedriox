<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;

/** Fired when a player swings without hitting a block or actor. */
final class PlayerMissSwingEvent extends CancellableEvent
{
    public function __construct(public readonly Player $player) {}
}
