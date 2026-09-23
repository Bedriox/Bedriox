<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Protocol;

enum WorkerFrameKind: int
{
    case HELLO = 1;
    case READY = 2;
    case SUBMIT = 3;
    case RESULT = 4;
    case FAILURE = 5;
    case CANCEL = 6;
    case CANCELLED = 7;
    case SHUTDOWN = 8;
    case HEARTBEAT = 9;
}
