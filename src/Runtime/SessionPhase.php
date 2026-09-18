<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

enum SessionPhase
{
    case LOGIN;
    case INITIALIZING;
    case ADMISSION_PENDING;
    case SPAWNED;
    case CLOSING;
}
