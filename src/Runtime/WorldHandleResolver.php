<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\World;

/** @internal Mutable composition bridge populated once the default runtime has been assembled. */
final class WorldHandleResolver
{
    private ?WorldRuntimeManager $worlds = null;

    public function attach(WorldRuntimeManager $worlds): void
    {
        $this->worlds = $worlds;
    }

    public function resolve(string $worldId): ?World
    {
        return $this->worlds?->get($worldId)?->handle;
    }
}
