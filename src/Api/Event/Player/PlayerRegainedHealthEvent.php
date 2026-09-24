<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Player\HealthRegainCause;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Immutable notification emitted after authoritative health restoration commits. */
final class PlayerRegainedHealthEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly HealthRegainCause $cause,
        public readonly float $amount,
    ) {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Health restoration must be finite and inside its bounded range.');
        }
    }
}
