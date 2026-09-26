<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/**
 * Built-in entity identities. The complete current-version catalog is admitted
 * by Data; cases are added here only when Bedriox implements their gameplay.
 */
enum VanillaEntityType: string implements VanillaEntityIdentity
{
    case COW = 'minecraft:cow';
    case ZOMBIE = 'minecraft:zombie';

    public function identifier(): string
    {
        return $this->value;
    }
}
