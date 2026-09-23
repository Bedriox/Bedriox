<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

enum PreparedChunkAvailability
{
    case READY;
    case PENDING;
    case DEFERRED;
    case SYNCHRONOUS_FALLBACK;
}
