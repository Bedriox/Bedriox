<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Immutable player projection available to AI without exposing mutable player state. */
final readonly class AiPlayerSnapshot
{
    public function __construct(
        public string $playerId,
        public string $worldName,
        public Position $position,
        public bool $damageable = true,
    ) {
        if ($playerId === '' || strlen($playerId) > 128 || preg_match('//u', $playerId) !== 1) {
            throw new InvalidArgumentException('AI player identity must be valid UTF-8 and bounded.');
        }
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('AI player world name must be valid UTF-8 and bounded.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('AI player position must be finite and bounded.');
        }
    }

    public function distanceSquaredTo(Position $position): float
    {
        $dx = $this->position->x - $position->x;
        $dy = $this->position->y - $position->y;
        $dz = $this->position->z - $position->z;

        return ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
    }
}
