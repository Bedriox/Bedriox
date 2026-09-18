<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum MovementMode: string
{
    case STOPPED = 'stopped';
    case WALKING = 'walking';
    case SPRINTING = 'sprinting';
    case JUMPING = 'jumping';
    case CROUCHING = 'crouching';
}
