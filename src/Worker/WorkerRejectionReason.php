<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

enum WorkerRejectionReason: string
{
    case DISABLED = 'disabled';
    case SHUTTING_DOWN = 'shutting-down';
    case UNKNOWN_TASK_TYPE = 'unknown-task-type';
    case INVALID_INPUT = 'invalid-input';
    case TASK_LIMIT = 'task-limit';
    case BYTE_LIMIT = 'byte-limit';
    case UNAVAILABLE_BROKER = 'unavailable-broker';
}
