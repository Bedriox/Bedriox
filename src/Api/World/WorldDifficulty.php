<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

enum WorldDifficulty: string
{
    case PEACEFUL = 'peaceful';
    case EASY = 'easy';
    case NORMAL = 'normal';
    case HARD = 'hard';
}
