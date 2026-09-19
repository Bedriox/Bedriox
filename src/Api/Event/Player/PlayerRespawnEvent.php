<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

final class PlayerRespawnEvent extends Event
{
    public function __construct(public readonly \Bedriox\Api\Player\Player $player, private Position $position) {}
    public function position(): Position
    {
        return $this->position;
    }
    public function setPosition(Position $position): void
    {
        $this->assertMutable();
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || $position->y < -64.0 || $position->y > 319.0) {
            throw new InvalidArgumentException('Respawn position is outside the supported world boundary.');
        }
        $this->position = $position;
    }
    protected function state(): mixed
    {
        return $this->position;
    }
    protected function replaceState(mixed $state): void
    {
        if (!$state instanceof Position) {
            throw new InvalidArgumentException('Invalid player respawn event state.');
        }
        $this->position = $state;
    }
}
