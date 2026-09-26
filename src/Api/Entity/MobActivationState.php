<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

enum MobActivationState: string
{
    case ACTIVE = 'active';
    case REDUCED = 'reduced';
    case SLEEPING = 'sleeping';
    case FORCED = 'forced';
}
