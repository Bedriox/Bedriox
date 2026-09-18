<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

enum WorldGeneratorType: string
{
    case Default = 'default';
    case Flat = 'flat';
}
