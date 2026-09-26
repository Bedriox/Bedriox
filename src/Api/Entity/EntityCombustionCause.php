<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/** Stable causes for attempts to ignite an entity. */
enum EntityCombustionCause: string
{
    case SUNLIGHT = 'sunlight';
    case PLUGIN = 'plugin';
}
