<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

enum PersistenceSubmission
{
    case ACCEPTED;
    case COALESCED;
    case STALE;
    case SATURATED;
}
