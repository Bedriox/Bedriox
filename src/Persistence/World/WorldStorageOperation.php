<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

enum WorldStorageOperation: int
{
    case LOAD_CHUNK = 32_101;
    case SAVE_CHUNK = 32_102;
    case SAVE_WORLD_DATA = 32_103;
}
