<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

enum AiControl: int
{
    case MOVE = 0;
    case LOOK = 1;
    case TARGET = 2;
    case JUMP = 3;
    case ATTACK = 4;
}
