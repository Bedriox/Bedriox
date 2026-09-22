<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Fully resolved authoritative state used consistently by play bootstrap and simulation admission. */
final readonly class PlayerBootstrap
{
    public function __construct(
        public PlayerIdentity $identity,
        public string $worldName,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public PlayerInventoryState $inventory,
        public int $firstPlayedAt,
        public int $lastPlayedAt,
        public string $gamemode = 'survival',
        public float $health = 20.0,
    ) {
        if ($this->worldName === '' || strlen($this->worldName) > 64
            || preg_match('//u', $this->worldName) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $this->worldName) === 1) {
            throw new InvalidArgumentException('Player world name must be valid non-control UTF-8 between 1 and 64 bytes.');
        }
        foreach (['x' => $this->position->x, 'y' => $this->position->y, 'z' => $this->position->z,
            'yaw' => $this->yaw, 'pitch' => $this->pitch] as $name => $value) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException("Player $name must be finite.");
            }
        }
        if (abs($this->position->x) > 30_000_000.0 || abs($this->position->z) > 30_000_000.0
            || $this->position->y < -64.0 || $this->position->y > 319.0) {
            throw new InvalidArgumentException('Player position exceeds the supported world boundary.');
        }
        if ($this->yaw < -360.0 || $this->yaw > 360.0 || $this->pitch < -90.0 || $this->pitch > 90.0) {
            throw new InvalidArgumentException('Player orientation exceeds its accepted range.');
        }
        if ($this->firstPlayedAt < 0 || $this->lastPlayedAt < $this->firstPlayedAt) {
            throw new InvalidArgumentException('Player timestamps are invalid.');
        }
        if (GameMode::tryFrom($this->gamemode) === null) {
            throw new InvalidArgumentException('Player gamemode is unsupported.');
        }
        if (!is_finite($this->health) || $this->health < 0.0 || $this->health > PlayerVitals::MAX_HEALTH) {
            throw new InvalidArgumentException('Player health must be finite and inside its authoritative range.');
        }
    }
}
