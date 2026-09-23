<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\Player;

enum PlayerStorageOperation: int
{
    case EXISTS = 32_201;
    case LOAD = 32_202;
    case SAVE = 32_203;
}
