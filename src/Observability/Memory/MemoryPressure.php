<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

enum MemoryPressure: int
{
    case NORMAL = 0;
    case ELEVATED = 1;
    case HIGH = 2;
    case CRITICAL = 3;
}
