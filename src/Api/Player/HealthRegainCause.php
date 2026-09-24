<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

/** Stable cause of an authoritative player health restoration. */
enum HealthRegainCause: string
{
    case SATURATION = 'saturation';
    case CUSTOM = 'custom';
}
