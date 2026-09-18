<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

/** An immutable snapshot of public world metadata. */
final readonly class World
{
    public function __construct(
        public string $name,
        public Position $spawn,
    ) {}
}
