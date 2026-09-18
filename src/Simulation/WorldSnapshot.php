<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

final readonly class WorldSnapshot
{
    /** @param list<PlayerSnapshot> $players */
    public function __construct(
        public int $tick,
        public array $players,
    ) {}
}
