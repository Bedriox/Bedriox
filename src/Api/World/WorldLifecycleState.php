<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

enum WorldLifecycleState: string
{
    case CREATING = 'creating';
    case LOADING = 'loading';
    case LOADED = 'loaded';
    case UNLOADING = 'unloading';
    case CLOSED = 'closed';
}
