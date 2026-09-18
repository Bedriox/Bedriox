<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

interface VersionedWorldGenerator extends WorldGenerator
{
    public function version(): int;
}
