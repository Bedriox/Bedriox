<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

enum WorkerResultStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case TIMED_OUT = 'timed-out';
    case BROKER_LOST = 'broker-lost';
}
