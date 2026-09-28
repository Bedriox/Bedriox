<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

final readonly class WorldUnloadOptions
{
    public function __construct(
        public bool $save = true,
    ) {}
}
