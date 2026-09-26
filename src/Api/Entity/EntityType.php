<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/** Stable namespaced identity for a vanilla or plugin-defined entity type. */
interface EntityType
{
    public function identifier(): string;
}
