<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

enum WorkerLane: int
{
    case CONTROL = 0;
    case AUTHENTICATION = 1;
    case WORLD = 2;
    case NETWORK = 3;
}
