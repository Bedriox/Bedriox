<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

enum WorldOperationFailureCode: string
{
    case INVALID_ID = 'invalid_id';
    case ALREADY_EXISTS = 'already_exists';
    case NOT_FOUND = 'not_found';
    case STALE_HANDLE = 'stale_handle';
    case DEFAULT_WORLD = 'default_world';
    case OCCUPIED = 'occupied';
    case BUSY = 'busy';
    case CANCELLED = 'cancelled';
    case STORAGE = 'storage';
    case GENERATOR = 'generator';
    case CAPACITY = 'capacity';
    case OWNER_DISABLED = 'owner_disabled';
    case UNKNOWN = 'unknown';
}
