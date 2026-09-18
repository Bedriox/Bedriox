<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

/** Storage-neutral representation of Bedrock chunk finalization state. */
enum ChunkFinalizationState: int
{
    case NeedsInstaticking = 0;
    case NeedsPopulation = 1;
    case Done = 2;
}
