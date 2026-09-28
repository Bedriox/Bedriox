<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

enum WorldOperationType: string
{
    case CREATE = 'create';
    case LOAD = 'load';
    case SAVE = 'save';
    case UNLOAD = 'unload';
}
