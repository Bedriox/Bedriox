<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

enum EventPriority: int
{
    case LOWEST = 0;
    case LOW = 25;
    case NORMAL = 50;
    case HIGH = 75;
    case HIGHEST = 100;
    case MONITOR = 125;
}
