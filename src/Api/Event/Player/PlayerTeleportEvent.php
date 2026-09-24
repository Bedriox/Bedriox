<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Runs before an authoritative player teleport is committed. */
final class PlayerTeleportEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Position $from,
        private Position $destination,
        private float $yaw,
        private float $pitch,
    ) {
        self::validateDestination($destination);
        self::validateOrientation($yaw, $pitch);
    }

    public function destination(): Position
    {
        return $this->destination;
    }

    public function yaw(): float
    {
        return $this->yaw;
    }

    public function pitch(): float
    {
        return $this->pitch;
    }

    public function setDestination(Position $destination): void
    {
        $this->assertMutable();
        self::validateDestination($destination);
        $this->destination = $destination;
    }

    public function setOrientation(float $yaw, float $pitch): void
    {
        $this->assertMutable();
        self::validateOrientation($yaw, $pitch);
        $this->yaw = $yaw;
        $this->pitch = $pitch;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->destination, $this->yaw, $this->pitch];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 4 || !is_bool($state[0])
            || !$state[1] instanceof Position || !is_float($state[2]) || !is_float($state[3])) {
            throw new InvalidArgumentException('Invalid player teleport event state.');
        }
        parent::replaceState($state[0]);
        $this->destination = $state[1];
        $this->yaw = $state[2];
        $this->pitch = $state[3];
    }

    private static function validateDestination(Position $destination): void
    {
        if (!is_finite($destination->x) || !is_finite($destination->y) || !is_finite($destination->z)
            || abs($destination->x) > 30_000_000.0 || abs($destination->z) > 30_000_000.0
            || $destination->y < -64.0 || $destination->y > 319.0) {
            throw new InvalidArgumentException('Teleport destination is outside the supported world boundary.');
        }
    }

    private static function validateOrientation(float $yaw, float $pitch): void
    {
        if (!is_finite($yaw) || !is_finite($pitch)
            || $yaw < -360.0 || $yaw > 360.0 || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Teleport orientation exceeds its accepted range.');
        }
    }
}
