<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

/** Immutable climate sample shared by terrain, biome, surface, and feature stages. */
final readonly class OverworldClimate
{
    public function __construct(
        public int $continentalness,
        public int $erosion,
        public int $temperature,
        public int $humidity,
        public int $ridge,
        public int $uplift,
        public int $river,
        public int $detail,
    ) {}
}
