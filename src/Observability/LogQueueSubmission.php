<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

enum LogQueueSubmission
{
    case ACCEPTED;
    case DROPPED;
}
