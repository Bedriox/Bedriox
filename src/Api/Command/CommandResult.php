<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

enum CommandResult
{
    case SUCCESS;
    case FAILURE;
    case USAGE;
}
