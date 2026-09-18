<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generation;

/** Final column-scale terrain values consumed by later generation stages. */
final readonly class OverworldTerrainSample
{
    public function __construct(
        public OverworldClimate $climate,
        public int $surfaceHeight,
        public int $slope,
        public int $riverStrength,
    ) {}
}
